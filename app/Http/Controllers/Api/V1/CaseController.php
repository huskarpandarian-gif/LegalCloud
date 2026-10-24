<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\RecordScope;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCaseRequest;
use App\Http\Requests\Api\V1\UpdateCaseRequest;
use App\Http\Resources\Api\V1\AuditEventResource;
use App\Http\Resources\Api\V1\CaseResource;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AuditEvent;
use App\Models\CaseParticipant;
use App\Models\Document;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class CaseController extends Controller
{
    public function index(Request $request, RecordScope $scope)
    {
        $this->authorize('viewAny', LegalCase::class);
        $query = $scope->cases($request->user())
            ->withCount('documents')
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('categoryId'), fn ($query, $id) => $query->where('category_id', $id));
        $perPage = min(max((int) $request->query('perPage', 20), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return CaseResource::collection($query->orderByDesc('updated_at')->paginate($perPage));
        }

        $referenceHash = LegalCase::referenceLookupHash($search);
        $cases = $query->orderByDesc('updated_at')->limit(5000)->get()
            ->filter(fn (LegalCase $case) => mb_stripos($case->title, $search) !== false
                || (is_string($case->reference_hash) && hash_equals($case->reference_hash, $referenceHash)))->values();
        $total = $cases->count();
        $cases = new LengthAwarePaginator(
            $cases->slice(($page - 1) * $perPage, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return CaseResource::collection($cases);
    }

    public function store(StoreCaseRequest $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        $case = LegalCase::query()->create([
            'organization_id' => $request->user()->organization_id,
            'category_id' => $data['categoryId'] ?? null,
            'owner_id' => $data['ownerId'] ?? $request->user()->public_id,
            'reference' => $data['reference'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'draft',
        ]);

        $audit->record($request, $request->user(), 'case.created', 'case', $case->id);

        return (new CaseResource($case->loadCount('documents')))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $caseId): CaseResource
    {
        $case = $this->findInOrganization($request, $caseId);
        $this->authorize('view', $case);

        return new CaseResource($case->loadCount('documents'));
    }

    public function update(UpdateCaseRequest $request, string $caseId, AuditLogger $audit): CaseResource
    {
        $case = $this->findInOrganization($request, $caseId);
        $this->authorize('update', $case);
        $data = $request->validated();
        $changes = [];
        foreach (['title', 'description', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (array_key_exists('categoryId', $data)) {
            $changes['category_id'] = $data['categoryId'];
        }
        if (array_key_exists('ownerId', $data)) {
            $changes['owner_id'] = $data['ownerId'];
        }
        $case->fill($changes)->save();
        $audit->record($request, $request->user(), 'case.updated', 'case', $case->id, $changes);

        return new CaseResource($case->loadCount('documents'));
    }

    public function documents(Request $request, string $caseId)
    {
        $case = $this->findInOrganization($request, $caseId);
        $this->authorize('view', $case);

        return DocumentResource::collection(
            app(RecordScope::class)->documents($request->user())
                ->where('case_id', $case->id)->with('currentVersion')->latest()->get()
        );
    }

    public function participants(Request $request, string $caseId)
    {
        $case = $this->findInOrganization($request, $caseId);
        $this->authorize('view', $case);
        $ids = CaseParticipant::query()->where('case_id', $case->id)->pluck('user_id');
        $users = User::query()->where('organization_id', $request->user()->organization_id)->whereIn('public_id', $ids)->get();

        return UserResource::collection($users);
    }

    public function storeParticipant(Request $request, string $caseId, AuditLogger $audit): JsonResponse
    {
        $this->ensureCanWrite($request);
        $case = $this->findInOrganization($request, $caseId);
        $this->authorize('update', $case);
        $data = $request->validate([
            'usuarioId' => ['required', 'uuid', Rule::exists('users', 'public_id')->where('organization_id', $request->user()->organization_id)],
        ]);
        $participant = CaseParticipant::query()->firstOrCreate(
            ['case_id' => $case->id, 'user_id' => $data['usuarioId']],
            ['assigned_by' => $request->user()->public_id],
        );
        $audit->record($request, $request->user(), 'case.participant_assigned', 'case', $case->id, ['usuarioId' => $data['usuarioId']]);

        return (new UserResource(User::query()->where('public_id', $participant->user_id)->firstOrFail()))->response()->setStatusCode(201);
    }

    public function destroyParticipant(Request $request, string $caseId, string $userId, AuditLogger $audit): JsonResponse
    {
        $this->ensureCanWrite($request);
        $case = $this->findInOrganization($request, $caseId);
        $this->authorize('update', $case);
        $participant = CaseParticipant::query()->where('case_id', $case->id)->where('user_id', $userId)->firstOrFail();
        $participant->delete();
        $audit->record($request, $request->user(), 'case.participant_removed', 'case', $case->id, ['usuarioId' => $userId]);

        return response()->json(null, 204);
    }

    public function transition(Request $request, string $caseId, AuditLogger $audit): CaseResource
    {
        $this->ensureCanWrite($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'open', 'in_review', 'closed', 'archived'])],
        ]);
        $case = $this->findInOrganization($request, $caseId);
        $this->authorize('update', $case);
        $from = $case->status;
        $case->forceFill(['status' => $data['status']])->save();
        $audit->record($request, $request->user(), 'case.status_changed', 'case', $case->id, ['from' => $from, 'to' => $data['status']]);

        return new CaseResource($case->loadCount('documents'));
    }

    public function trace(Request $request, string $caseId)
    {
        abort_unless($request->user()->allows('audit.read'), 403);
        $case = $this->findInOrganization($request, $caseId);
        $this->authorize('view', $case);

        return AuditEventResource::collection(
            AuditEvent::query()->where('organization_id', $request->user()->organization_id)
                ->where('resource_type', 'case')->where('resource_id', $case->id)->orderByDesc('occurred_at')->get()
        );
    }

    private function ensureCanWrite(Request $request): void
    {
        abort_unless($request->user()->allows('cases.write'), 403);
    }

    private function findInOrganization(Request $request, string $caseId): LegalCase
    {
        return app(RecordScope::class)->cases($request->user())->whereKey($caseId)->firstOrFail();
    }
}
