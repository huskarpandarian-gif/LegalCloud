<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\EmbeddingProvider;
use App\Domain\AI\EmbeddingProviderNotConfigured;

class UnavailableEmbeddingProvider implements EmbeddingProvider
{
    public function embed(string $text): array
    {
        throw new EmbeddingProviderNotConfigured('No embedding provider has been configured.');
    }
}
