<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\OcrProvider;
use App\Domain\AI\ProviderNotConfigured;

class UnavailableOcrProvider implements OcrProvider
{
    public function extract(string $path, string $mimeType): array
    {
        throw new ProviderNotConfigured('No OCR provider has been configured.');
    }
}
