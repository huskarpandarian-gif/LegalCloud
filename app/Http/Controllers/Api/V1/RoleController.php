<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\RoleCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request, RoleCatalog $roles): JsonResponse
    {
        return response()->json(['data' => array_values($roles->all($request->user()->organization))]);
    }

    public function show(Request $request, string $roleId, RoleCatalog $roles): JsonResponse
    {
        return response()->json(['data' => $roles->find($roleId, $request->user()->organization)]);
    }

    public function update(Request $request, string $roleId, RoleCatalog $roles, AuditLogger $audit): JsonResponse
    {
        $this->ensureAdministrator($request);
        $data = $request->validate(['label' => ['required', 'string', 'min:2', 'max:80']]);
        $role = $roles->rename($request->user()->organization, $roleId, $data['label']);
        $audit->record($request, $request->user(), 'role.updated', 'role', $request->user()->organization_id, ['role' => $roleId]);

        return response()->json(['data' => $role]);
    }

    public function syncPermissions(Request $request, string $roleId, RoleCatalog $roles, AuditLogger $audit): JsonResponse
    {
        $this->ensureAdministrator($request);
        $data = $request->validate([
            'permisos' => ['required', 'array'],
            'permisos.*' => ['string'],
        ]);
        $role = $roles->replacePermissions($request->user()->organization, $roleId, $data['permisos']);
        $audit->record($request, $request->user(), 'role.permissions_replaced', 'role', $request->user()->organization_id, ['role' => $roleId]);

        return response()->json(['data' => $role]);
    }

    public function permissions(RoleCatalog $roles): JsonResponse
    {
        return response()->json(['data' => $roles->permissions()]);
    }

    private function ensureAdministrator(Request $request): void
    {
        abort_unless($request->user()->allows('users.manage'), 403);
    }
}
