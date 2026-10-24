<?php

namespace Tests\Feature;

use App\Domain\AI\LlmProviderFailed;
use App\Domain\AI\ProviderNotConfigured;
use App\Domain\AI\Providers\OpenAiLlmProvider;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiLlmProviderTest extends TestCase
{
    public function test_it_sends_grounded_context_to_the_configured_openai_model(): void
    {
        config([
            'services.openai.api_key' => 'test-only-key',
            'services.openai.base_url' => 'https://api.openai.com/v1',
            'services.openai.model' => 'gpt-4.1-mini',
        ]);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['output_text' => 'La norma fija quince días [S1].'], 200)]);

        $answer = app(OpenAiLlmProvider::class)->answer('¿Cuál es el plazo?', [[
            'content' => 'El plazo es de quince días.',
            'citation' => ['title' => 'Resolución 118', 'page' => 3],
        ]]);

        $this->assertSame('La norma fija quince días [S1].', $answer);
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer test-only-key')
            && $request->data()['model'] === 'gpt-4.1-mini'
            && $request->data()['store'] === false
            && str_contains($request->data()['input'], 'El plazo es de quince días.')
        );
    }

    public function test_it_refuses_to_call_openai_without_an_api_key(): void
    {
        config(['services.openai.api_key' => null]);
        Http::preventStrayRequests();

        $this->expectException(ProviderNotConfigured::class);
        app(OpenAiLlmProvider::class)->answer('Pregunta', []);
    }

    public function test_it_normalizes_unsuccessful_openai_responses(): void
    {
        config(['services.openai.api_key' => 'test-only-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => 'rate limited'], 429)]);

        $this->expectException(LlmProviderFailed::class);
        app(OpenAiLlmProvider::class)->answer('Pregunta', []);
    }

    public function test_summary_mode_is_explicit_in_the_openai_instructions(): void
    {
        config(['services.openai.api_key' => 'test-only-key']);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['output_text' => 'Resumen [S1].'], 200)]);

        app(OpenAiLlmProvider::class)->answer('Resume la norma', [[
            'content' => 'La norma tiene tres artículos.',
            'citation' => ['title' => 'Norma de prueba', 'page' => 1],
        ]], 'summary');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains(
            $request->data()['instructions'],
            'Entrega un resumen breve',
        ));
    }
}
