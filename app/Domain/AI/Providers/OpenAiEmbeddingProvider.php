<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\EmbeddingProvider;
use App\Domain\AI\EmbeddingProviderFailed;
use App\Domain\AI\EmbeddingProviderNotConfigured;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        $apiKey = config('services.openai.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new EmbeddingProviderNotConfigured('OPENAI_API_KEY is required to create embeddings.');
        }

        $input = trim($text);
        if ($input === '') {
            throw new EmbeddingProviderFailed('The embedding provider received empty text.');
        }

        $model = (string) config('services.openai.embedding_model', 'text-embedding-3-small');
        $payload = [
            'model' => $model,
            'input' => mb_substr($input, 0, 20000),
            'encoding_format' => 'float',
        ];
        if (str_contains($model, 'text-embedding-3')) {
            $payload['dimensions'] = (int) config('legalcloud.vector.dimensions', 1536);
        }

        try {
            $response = Http::baseUrl(rtrim((string) config('services.openai.base_url'), '/'))
                ->withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout((int) config('services.openai.timeout', 45))
                ->post('/embeddings', $payload);
        } catch (ConnectionException $exception) {
            throw new EmbeddingProviderFailed('The configured embedding provider could not be reached.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new EmbeddingProviderFailed('The configured embedding provider returned an unsuccessful response.');
        }

        $embedding = $response->json('data.0.embedding');
        if (! is_array($embedding) || $embedding === []) {
            throw new EmbeddingProviderFailed('The configured embedding provider returned no vector.');
        }

        return array_map(static fn (mixed $value): float => (float) $value, $embedding);
    }
}
