<?php

namespace Tests\Feature;

use App\Domain\AI\EmbeddingProviderFailed;
use App\Domain\AI\EmbeddingProviderNotConfigured;
use App\Domain\AI\Providers\OpenAiEmbeddingProvider;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiEmbeddingProviderTest extends TestCase
{
    public function test_it_requests_a_vector_with_the_configured_dimensions(): void
    {
        config([
            'services.openai.api_key' => 'test-only-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.embedding_model' => 'text-embedding-3-small',
            'legalcloud.vector.dimensions' => 1536,
        ]);
        Http::fake(['api.openai.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => [0.25, 0.5]]],
        ], 200)]);

        $vector = app(OpenAiEmbeddingProvider::class)->embed('quince días hábiles');

        $this->assertSame([0.25, 0.5], $vector);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.openai.com/v1/embeddings'
            && $request->hasHeader('Authorization', 'Bearer test-only-key')
            && $request->data()['model'] === 'text-embedding-3-small'
            && $request->data()['dimensions'] === 1536
            && $request->data()['input'] === 'quince días hábiles'
        );
    }

    public function test_it_refuses_to_call_openai_without_an_api_key(): void
    {
        config(['services.openai.api_key' => null]);
        Http::preventStrayRequests();

        $this->expectException(EmbeddingProviderNotConfigured::class);
        app(OpenAiEmbeddingProvider::class)->embed('consulta');
    }

    public function test_it_normalizes_unsuccessful_embedding_responses(): void
    {
        config(['services.openai.api_key' => 'test-only-key']);
        Http::fake(['api.openai.com/v1/embeddings' => Http::response(['error' => 'unavailable'], 503)]);

        $this->expectException(EmbeddingProviderFailed::class);
        app(OpenAiEmbeddingProvider::class)->embed('consulta');
    }
}
