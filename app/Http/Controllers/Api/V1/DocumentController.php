<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\RecordScope;
use App\Domain\AI\VectorIndexService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentTextSearch;
use App\Domain\Documents\HybridSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Http\Requests\Api\V1\UpdateDocumentRequest;
use App\Http\Requests\Api\V1\UploadDocumentRequest;
use App\Http\Requests\Api\V1\UploadDocumentVersionRequest;
use App\Http\Resources\Api\V1\DocumentResource;
use App\Http\Resources\Api\V1\DocumentVersionResource;
use App\Jobs\ProcessDocumentOcr;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\LegalCase;
use App\Models\ProcessingJob;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DocumentController extends Controller
{
    public function index(Request $request, RecordScope $scope)
    {
        $this->authorize('viewAny', Document::class);
        $documents = $scope->documents($request->user())
            ->with('currentVersion')
            ->when($request->query('caseId'), fn (Builder $query, string $id) => $query->where('case_id', $id))
            ->when($request->query('categoryId'), fn (Builder $query, string $id) => $query->where('category_id', $id))
            ->when($request->query('status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->latest()->paginate(min(max((int) $request->query('perPage', 20), 1), 100));

        return DocumentResource::collection($documents);
    }

    public function storeInCase(Request $request, string $caseId, AuditLogger $audit): JsonResponse
    {
        $request->merge(['caseId' => $caseId]);

        return $this->store($this->uploadRequest($request), $audit);
    }

    public function store(UploadDocumentRequest $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        $file = $request->file('file');
        $path = null;

        try {
            $document = DB::transaction(function () use ($request, $audit, $data, $file, &$path): Document {
                $case = app(RecordScope::class)->cases($request->user())->findOrFail($data['caseId']);
                $this->authorize('view', $case);
                $this->authorize('create', Document::class);
                $document = Document::query()->create([
                    'organization_id' => $request->user()->organization_id,
                    'case_id' => $case->id,
                    'category_id' => $data['categoryId'] ?? null,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'status' => 'processing',
                ]);
                $version = $this->persistVersion($document, $file, $request->user()->public_id, null, $path);
                $document->update(['current_version_id' => $version->id]);
                $job = $this->queueOcr($document, $version);
                $audit->record($request, $request->user(), 'document.uploaded', 'document', $document->id, ['versionId' => $version->id]);
                ProcessDocumentOcr::dispatch($job->id)->afterCommit();

                return $document->load('currentVersion');
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk(config('legalcloud.documents.disk', 'private'))->delete($path);
            }
            throw $exception;   
        }

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $documentId): DocumentResource
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('view', $document);

        return new DocumentResource($document->load('currentVersion'));
    }

    public function update(UpdateDocumentRequest $request, string $documentId, AuditLogger $audit): DocumentResource
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('update', $document);
        $data = $request->validated();
        $changes = [];
        foreach (['title', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (array_key_exists('categoryId', $data)) {
            $changes['category_id'] = $data['categoryId'];
        }
        $document->fill($changes)->save();
        $audit->record($request, $request->user(), 'document.updated', 'document', $document->id, $changes);

        return new DocumentResource($document->load('currentVersion'));
    }

    public function destroy(Request $request, string $documentId, AuditLogger $audit): JsonResponse
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('delete', $document);
        $document->delete();
        $audit->record($request, $request->user(), 'document.archived', 'document', $document->id);

        return response()->json(null, 204);
    }

    public function versions(Request $request, string $documentId)
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('view', $document);

        return DocumentVersionResource::collection($document->versions()->get());
    }

    public function storeVersion(UploadDocumentVersionRequest $request, string $documentId, AuditLogger $audit): JsonResponse
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('update', $document);
        $file = $request->file('file');
        $path = null;

        try {
            [$version, $job] = DB::transaction(function () use ($request, $audit, $document, $file, &$path): array {
                $lockedDocument = Document::query()->where('organization_id', $request->user()->organization_id)->lockForUpdate()->findOrFail($document->id);
                $version = $this->persistVersion($lockedDocument, $file, $request->user()->public_id, $request->validated('changeNote'), $path);
                $lockedDocument->update([
                    'current_version_id' => $version->id,
                    'mime_type' => $version->mime_type,
                    'size_bytes' => $version->size_bytes,
                    'status' => 'processing',
                ]);
                $job = $this->queueOcr($lockedDocument, $version);
                $audit->record($request, $request->user(), 'document.version_created', 'document', $lockedDocument->id, ['versionId' => $version->id]);
                ProcessDocumentOcr::dispatch($job->id)->afterCommit();

                return [$version, $job];
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk(config('legalcloud.documents.disk', 'private'))->delete($path);
            }
            throw $exception;
        }

        return (new DocumentVersionResource($version))->response()->setStatusCode(201);
    }

    public function download(Request $request, string $documentId, string $versionId)
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('download', $document);
        $version = $document->versions()->whereKey($versionId)->firstOrFail();
        app(AuditLogger::class)->record($request, $request->user(), 'document.downloaded', 'document', $document->id, ['versionId' => $version->id]);
        $storedFile = Storage::disk($version->disk)->get($version->path);
        $contents = (int) $version->file_encryption_version >= 1
            ? Crypt::decryptString($storedFile)
            : $storedFile;

        return response($contents, 200, [
            'Content-Type' => $version->mime_type,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => "attachment; filename*=UTF-8''".rawurlencode(basename($version->file_name)),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function search(SearchRequest $request): JsonResponse
    {
        return response()->json($this->runSearch($request->user(), $request->validated()));
    }

    public function storeSavedSearch(SearchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $saved = SavedSearch::query()->create([
            'organization_id' => $request->user()->organization_id,
            'user_id' => $request->user()->id,
            'name' => mb_substr((string) ($request->input('name') ?: $data['query']), 0, 160),
            'query' => $data['query'],
            'filters' => array_filter([
                'mode' => $data['mode'] ?? 'hybrid',
                'caseId' => $data['caseId'] ?? null,
            ]),
        ]);
        $results = $this->runSearch($request->user(), $data);
        $results['id'] = $saved->id;
        $results['name'] = $saved->name;

        return response()->json($results, 201);
    }

    public function savedSearches(Request $request)
    {
        $searches = SavedSearch::query()->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->id)->latest()->get();

        return response()->json(['data' => $searches->map(fn (SavedSearch $search) => [
            'id' => $search->id,
            'name' => $search->name,
            'query' => $search->query,
            'filters' => $search->filters,
            'createdAt' => $search->created_at,
        ])->values()]);
    }

    public function showSavedSearch(Request $request, string $searchId): JsonResponse
    {
        $saved = SavedSearch::query()->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->id)->whereKey($searchId)->firstOrFail();
        $filters = is_array($saved->filters) ? $saved->filters : [];
        $results = $this->runSearch($request->user(), [
            'query' => $saved->query,
            'mode' => $filters['mode'] ?? 'hybrid',
            'caseId' => $filters['caseId'] ?? null,
            'page' => 1,
            'perPage' => 20,
        ]);
        $results['id'] = $saved->id;
        $results['name'] = $saved->name;

        return response()->json($results);
    }

    public function beginUpload(Request $request, string $caseId): JsonResponse
    {
        $case = app(RecordScope::class)->cases($request->user())->findOrFail($caseId);
        $this->authorize('view', $case);
        $this->authorize('create', Document::class);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'categoryId' => ['nullable', 'uuid'],
        ]);
        $token = (string) Str::uuid();
        Cache::put($this->uploadCacheKey($token), [
            'organizationId' => $request->user()->organization_id,
            'userId' => $request->user()->public_id,
            'caseId' => $case->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'categoryId' => $data['categoryId'] ?? null,
        ], now()->addHour());

        return response()->json([
            'token' => $token,
            'uploadUrl' => url('/api/v1/cargas/'.$token),
            'expiresIn' => 3600,
        ], 202);
    }

    public function receiveUpload(Request $request, string $token, AuditLogger $audit): JsonResponse
    {
        $pending = Cache::pull($this->uploadCacheKey($token));
        abort_unless(is_array($pending) && $pending['organizationId'] === $request->user()->organization_id && $pending['userId'] === $request->user()->public_id, 404);
        $request->merge([
            'caseId' => $pending['caseId'],
            'title' => $pending['title'],
            'description' => $pending['description'],
            'categoryId' => $pending['categoryId'],
        ]);

        return $this->store($this->uploadRequest($request), $audit);
    }

    public function archive(Request $request, string $documentId, AuditLogger $audit): JsonResponse
    {
        return $this->destroy($request, $documentId, $audit);
    }

    public function showVersion(Request $request, string $documentId, string $versionId): DocumentVersionResource
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('view', $document);

        return new DocumentVersionResource($document->versions()->whereKey($versionId)->firstOrFail());
    }

    public function confirmVersion(Request $request, string $documentId, string $versionId, AuditLogger $audit): DocumentVersionResource
    {
        return $this->promoteVersion($request, $documentId, $versionId, $audit, 'document.version_confirmed');
    }

    public function restoreVersion(Request $request, string $documentId, string $versionId, AuditLogger $audit): DocumentVersionResource
    {
        return $this->promoteVersion($request, $documentId, $versionId, $audit, 'document.version_restored');
    }

    public function versionText(Request $request, string $documentId, string $versionId): JsonResponse
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('view', $document);
        $version = $document->versions()->whereKey($versionId)->firstOrFail();

        return response()->json([
            'versionId' => $version->id,
            'text' => $version->extracted_text,
        ]);
    }

    public function downloadCurrent(Request $request, string $documentId)
    {
        $document = $this->findDocument($request, $documentId);
        abort_unless($document->current_version_id, 404);

        return $this->download($request, $documentId, $document->current_version_id);
    }

    public function storeExample(Request $request, AuditLogger $audit): JsonResponse
    {
        $case = app(RecordScope::class)->cases($request->user())->latest()->first();
        abort_unless($case, 422, 'Crea un expediente antes de generar un documento de ejemplo.');
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('ejemplo-legalcloud.txt', "Documento de ejemplo\nOrganización: expediente {$case->reference}\n");
        $request->files->set('file', $file);
        $request->merge(['caseId' => $case->id, 'title' => 'Documento de ejemplo', 'description' => 'Generado desde la web para probar la carga.']);

        return $this->store($this->uploadRequest($request), $audit);
    }

    private function promoteVersion(Request $request, string $documentId, string $versionId, AuditLogger $audit, string $action): DocumentVersionResource
    {
        $document = $this->findDocument($request, $documentId);
        $this->authorize('update', $document);
        $version = $this->findVersion($request, $documentId, $versionId);
        $document->update([
            'current_version_id' => $version->id,
            'mime_type' => $version->mime_type,
            'size_bytes' => $version->size_bytes,
        ]);
        $audit->record($request, $request->user(), $action, 'document', $document->id, ['versionId' => $version->id]);

        return new DocumentVersionResource($version);
    }

    private function findVersion(Request $request, string $documentId, string $versionId): DocumentVersion
    {
        return $this->findDocument($request, $documentId)->versions()->whereKey($versionId)->firstOrFail();
    }

    private function uploadRequest(Request $request): UploadDocumentRequest
    {
        $form = UploadDocumentRequest::createFrom($request);
        $form->setContainer(app());
        $form->setRedirector(app('redirect'));
        $form->setUserResolver(fn () => $request->user());

        return $form;
    }

    private function uploadCacheKey(string $token): string
    {
        return 'legalcloud.upload.'.$token;
    }

    /** @param  array<string, mixed>  $data */
    private function runSearch(User $user, array $data): array
    {
        $mode = $data['mode'] ?? 'hybrid';
        $page = (int) ($data['page'] ?? 1);
        $perPage = min(max((int) ($data['perPage'] ?? 20), 1), 100);
        $query = trim((string) $data['query']);
        $narrowed = app(RecordScope::class)->narrow($user, $data);
        if ($narrowed === null) {
            return [
                'data' => [],
                'meta' => ['currentPage' => $page, 'perPage' => $perPage, 'total' => 0, 'lastPage' => 1],
            ];
        }
        $data = $narrowed;
        $organizationId = $user->organization_id;
        $vectors = app(VectorIndexService::class);
        $textSearch = app(DocumentTextSearch::class);
        $hybrid = app(HybridSearch::class);

        if ($mode === 'semantic') {
            return $vectors->search($query, $organizationId, $data, $page, $perPage);
        }

        if ($mode === 'hybrid' && config('legalcloud.vector.enabled')) {
            return $hybrid->search($query, $organizationId, $data, $page, $perPage);
        }

        return $textSearch->search($query, $organizationId, $data, $page, $perPage);
    }

    private function findDocument(Request $request, string $documentId): Document
    {
        return app(RecordScope::class)->documents($request->user())->whereKey($documentId)->firstOrFail();
    }

    private function persistVersion(Document $document, mixed $file, string $uploadedBy, ?string $changeNote, ?string &$storedPath): DocumentVersion
    {
        $versionNumber = ((int) $document->versions()->max('version_number')) + 1;
        $storedPath = "organizations/{$document->organization_id}/documents/{$document->id}/version-{$versionNumber}-".Str::uuid().'.enc';

        $encryptedFile = Crypt::encryptString($file->get());

        $disk = config('legalcloud.documents.disk', 'private');

        if (! Storage::disk($disk)->put($storedPath, $encryptedFile)) {
            throw new \RuntimeException('The document could not be stored.');
        }

        return $document->versions()->create([
            'version_number' => $versionNumber,
            'file_name' => basename($file->getClientOriginalName()),
            'disk' => $disk,
            'path' => $storedPath,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'change_note' => $changeNote,
            'uploaded_by' => $uploadedBy,
            'file_encryption_version' => 1,
        ]);
    }

    private function queueOcr(Document $document, DocumentVersion $version): ProcessingJob
    {
        return ProcessingJob::query()->create([
            'organization_id' => $document->organization_id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'type' => 'ocr',
            'status' => 'queued',
            'progress' => 0,
        ]);
    }
}
