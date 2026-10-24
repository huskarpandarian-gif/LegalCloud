<?php

namespace App\Http\Controllers\Web;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => (new UserResource($request->user()))->resolve()]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
        ]);
        if (isset($data['email'])) {
            $taken = User::query()->where('email_hash', User::emailLookupHash($data['email']))
                ->whereKeyNot($request->user()->id)->exists();
            abort_if($taken, 422, 'Ya existe una cuenta con ese correo.');
        }
        $request->user()->fill($data)->save();
        $audit->record($request, $request->user(), 'profile.updated', 'user', $request->user()->public_id);

        return response()->json(['data' => (new UserResource($request->user()->refresh()))->resolve()]);
    }

    public function destroy(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $audit->record($request, $user, 'profile.deleted', 'user', $user->public_id);
        $user->forceFill(['status' => 'suspended'])->save();
        $user->accessTokens()->delete();

        return response()->json(null, 204);
    }

    public function updatePassword(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);
        abort_unless(Hash::check($data['currentPassword'], $request->user()->password), 422, 'La contraseña actual no coincide.');
        $request->user()->forceFill(['password' => $data['password']])->save();
        $audit->record($request, $request->user(), 'profile.password_updated', 'user', $request->user()->public_id);

        return response()->json(null, 204);
    }
}
