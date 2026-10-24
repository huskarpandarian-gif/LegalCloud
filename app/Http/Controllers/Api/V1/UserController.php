<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\RoleCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\LegalCase;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->where('organization_id', $request->user()->organization_id)
            ->when($request->query('role'), fn ($query, $role) => $query->where('role', $role));
        $perPage = min(max((int) $request->query('perPage', 20), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return UserResource::collection($query->orderBy('id')->paginate($perPage));
        }

        $emailHash = filter_var($search, FILTER_VALIDATE_EMAIL) ? User::emailLookupHash($search) : null;
        $users = $query->orderBy('id')->limit(5000)->get()
            ->filter(fn (User $user) => str_contains(mb_strtolower($user->name), mb_strtolower($search))
                || ($emailHash !== null && hash_equals((string) $user->email_hash, $emailHash)))->values();
        $total = $users->count();
        $users = new LengthAwarePaginator(
            $users->slice(($page - 1) * $perPage, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request, AuditLogger $audit): JsonResponse
    {
        $user = User::query()->create([
            ...$request->validated(),
            'organization_id' => $request->user()->organization_id,
            'status' => 'active',
        ]);

        $audit->record($request, $request->user(), 'user.created', 'user', $user->public_id, ['role' => $user->role]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $userId): UserResource
    {
        return new UserResource($this->findInOrganization($request, $userId));
    }

    public function update(UpdateUserRequest $request, string $userId, AuditLogger $audit): UserResource
    {
        $user = $this->findInOrganization($request, $userId);
        $changes = $request->validated();
        abort_if($user->is($request->user()) && ($changes['status'] ?? null) === 'suspended', 422, 'You cannot suspend your own account.');
        $user->fill($changes)->save();
        $audit->record($request, $request->user(), 'user.updated', 'user', $user->public_id, array_intersect_key($changes, array_flip(['role', 'status'])));

        return new UserResource($user);
    }

    public function destroy(Request $request, string $userId, AuditLogger $audit): JsonResponse
    {
        $user = $this->findInOrganization($request, $userId);
        if ($user->is($request->user())) {
            return response()->json([
                'code' => 'cannot_delete_self',
                'message' => 'No puedes eliminar tu propia cuenta.',
            ], 422);
        }

        $audit->record($request, $request->user(), 'user.deleted', 'user', $user->public_id, ['role' => $user->role]);
        LegalCase::query()
            ->where('organization_id', $user->organization_id)
            ->where('owner_id', $user->public_id)
            ->update(['owner_id' => null]);
        $user->delete();

        return response()->json(null, 204);
    }

    public function suspend(Request $request, string $userId, AuditLogger $audit): UserResource
    {
        return $this->changeStatus($request, $userId, 'suspended', $audit, 'user.suspended');
    }

    public function reactivate(Request $request, string $userId, AuditLogger $audit): UserResource
    {
        return $this->changeStatus($request, $userId, 'active', $audit, 'user.reactivated');
    }

    public function syncRoles(Request $request, string $userId, AuditLogger $audit, RoleCatalog $roles): UserResource
    {
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1', 'max:1'],
            'roles.*' => ['required', 'string'],
        ]);
        $roleId = $data['roles'][0];
        $roles->find($roleId, $request->user()->organization);
        $user = $this->findInOrganization($request, $userId);
        abort_if($user->is($request->user()) && $roleId !== 'administrator' && $user->role === 'administrator', 422, 'No puedes quitarte el rol de administrador.');
        $user->forceFill(['role' => $roleId])->save();
        $audit->record($request, $request->user(), 'user.roles_replaced', 'user', $user->public_id, ['role' => $roleId]);

        return new UserResource($user);
    }

    private function changeStatus(Request $request, string $userId, string $status, AuditLogger $audit, string $action): UserResource
    {
        $user = $this->findInOrganization($request, $userId);
        abort_if($user->is($request->user()) && $status === 'suspended', 422, 'No puedes suspender tu propia cuenta.');
        $user->forceFill(['status' => $status])->save();
        $audit->record($request, $request->user(), $action, 'user', $user->public_id, ['status' => $status]);

        return new UserResource($user);
    }

    private function findInOrganization(Request $request, string $userId): User
    {
        return User::query()->where('organization_id', $request->user()->organization_id)->where('public_id', $userId)->firstOrFail();
    }
}
