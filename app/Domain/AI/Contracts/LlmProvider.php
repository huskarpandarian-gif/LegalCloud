<?php

namespace App\Domain\AI\Contracts;

interface LlmProvider
{
    /** @param list<array{content: string, citation: array<string, mixed>}> $sources */
    public function answer(string $question, array $sources, string $mode = 'answer'): string;
}
