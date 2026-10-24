<?php

namespace App\Domain\AI\Contracts;

interface EmbeddingProvider
{
    /** @return list<float> */
    public function embed(string $text): array;
}
