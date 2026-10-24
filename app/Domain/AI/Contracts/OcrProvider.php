<?php

namespace App\Domain\AI\Contracts;

interface OcrProvider
{
    /** @return array{text: string, pages: array<int, string>} */
    public function extract(string $path, string $mimeType): array;
}
