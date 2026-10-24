<?php

namespace App\Domain\Identity;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Carbon;

class ApiTokenService
{
    /** @return array{plainTextToken: string, expiresAt: Carbon} */
    public function issue(User $user, string $name = 'api-client'): array
    {
        $plainTextToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addDays((int) config('legalcloud.api_token_ttl_days', 30));

        $user->accessTokens()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'abilities' => ['*'],
            'expires_at' => $expiresAt,
        ]);

        return ['plainTextToken' => $plainTextToken, 'expiresAt' => $expiresAt];
    }

    public function revoke(PersonalAccessToken $token): void
    {
        $token->delete();
    }
}
