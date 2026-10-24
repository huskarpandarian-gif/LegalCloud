<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\LlmProvider;
use App\Domain\AI\ProviderNotConfigured;

class UnavailableLlmProvider implements LlmProvider
{
    public function answer(string $question, array $sources, string $mode = 'answer'): string
    {
        throw new ProviderNotConfigured('No LLM provider has been configured.');
    }
}
