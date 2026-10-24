<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\ApiTokenService;
use App\Domain\Identity\RoleCatalog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(LoginRequest $request, ApiTokenService $tokens, AuditLogger $audit, RoleCatalog $roles): JsonResponse
    {
        $user = User::query()->where('email_hash', User::emailLookupHash($request->validated('email')))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password) || $user->status !== 'active' || ! $user->organization) {
            return response()->json(['code' => 'unauthenticated', 'message' => 'The supplied credentials are invalid.'], 401);
        }

        $token = $tokens->issue($user);
        $audit->record($request, $user, 'auth.login', 'user', $user->public_id);

        return response()->json([
            'accessToken' => $token['plainTextToken'],
            'tokenType' => 'Bearer',
            'expiresAt' => $token['expiresAt']->toISOString(),
            'home' => $roles->homePath($user),
            'user' => (new UserResource($user))->resolve(),
            'organization' => [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
            ],
        ]);
    }

    public function register(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'organization' => ['required', 'string', 'min:2', 'max:180'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ]);

        if (User::query()->where('email_hash', User::emailLookupHash($data['email']))->exists()) {
            return response()->json(['code' => 'email_taken', 'message' => 'Ya existe una cuenta con ese correo.'], 422);
        }

        $baseSlug = Str::slug($data['organization']);
        if ($baseSlug === '') {
            return response()->json(['code' => 'invalid_organization', 'message' => 'El nombre de la organización debe incluir letras o números.'], 422);
        }

        $slug = $baseSlug;
        for ($suffix = 2; Organization::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$baseSlug}-{$suffix}";
        }

        $user = DB::transaction(function () use ($data, $slug): User {
            $organization = Organization::query()->create(['name' => $data['organization'], 'slug' => $slug]);

            return User::query()->create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'administrator',
                'status' => 'active',
            ]);
        });

        $audit->record($request, $user, 'auth.registered', 'user', $user->public_id);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function logout(Request $request, ApiTokenService $tokens, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $accessToken = $request->attributes->get('api_access_token');

        if ($accessToken) {
            $tokens->revoke($accessToken);
        }

        $audit->record($request, $user, 'auth.logout', 'user', $user->public_id);

        return response()->json(null, 204);
    }
}
