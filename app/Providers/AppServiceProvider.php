<?php

namespace App\Providers;

use App\Domain\AI\Contracts\EmbeddingProvider;
use App\Domain\AI\Contracts\LlmProvider;
use App\Domain\AI\Contracts\OcrProvider;
use App\Domain\AI\Providers\ExtractiveLlmProvider;
use App\Domain\AI\Providers\NativeDocumentTextProvider;
use App\Domain\AI\Providers\OpenAiEmbeddingProvider;
use App\Domain\AI\Providers\OpenAiLlmProvider;
use App\Domain\AI\Providers\UnavailableEmbeddingProvider;
use App\Domain\AI\Providers\UnavailableOcrProvider;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OcrProvider::class, function ($app): OcrProvider {
            if (config('legalcloud.ocr.provider') === 'native') {
                return $app->make(NativeDocumentTextProvider::class);
            }

            return $app->make(UnavailableOcrProvider::class);
        });
        $this->app->bind(EmbeddingProvider::class, function ($app): EmbeddingProvider {
            if (config('legalcloud.embeddings.enabled') && config('legalcloud.embeddings.provider') === 'openai') {
                return $app->make(OpenAiEmbeddingProvider::class);
            }

            return $app->make(UnavailableEmbeddingProvider::class);
        });
        $this->app->bind(LlmProvider::class, function ($app): LlmProvider {
            $apiKey = config('services.openai.api_key');
            $openAiReady = config('legalcloud.ai.enabled')
                && config('legalcloud.ai.provider') === 'openai'
                && is_string($apiKey)
                && trim($apiKey) !== '';

            return $openAiReady
                ? $app->make(OpenAiLlmProvider::class)
                : $app->make(ExtractiveLlmProvider::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip().'|'.strtolower((string) $request->input('email'))));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by((string) $request->user()?->getAuthIdentifier()));

        Auth::viaRequest('api-token', function (Request $request): ?User {
            $plainTextToken = $request->bearerToken();

            if (! $plainTextToken) {
                return null;
            }

            // Persist only a digest; the bearer token is revealed once at creation.
            $accessToken = PersonalAccessToken::query()
                ->with('user')
                ->where('token_hash', hash('sha256', $plainTextToken))
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();

            if (! $accessToken || ! $accessToken->user || $accessToken->user->status !== 'active') {
                return null;
            }

            $accessToken->forceFill(['last_used_at' => now()])->saveQuietly();
            $request->attributes->set('api_access_token', $accessToken);

            return $accessToken->user;
        });
    }
}
