<?php

namespace App\Jobs;

use App\Domain\AI\EmbeddingProviderFailed;
use App\Domain\AI\EmbeddingProviderNotConfigured;
use App\Domain\AI\ProviderNotConfigured;
use App\Domain\AI\VectorIndexService;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\ProcessingJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IndexDocumentEmbeddings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $documentVersionId,
        public readonly ?string $processingJobId = null,
    ) {
        $this->onQueue('document-processing');
    }

    public function handle(VectorIndexService $vectors): void
    {
        $job = $this->processingJobId === null
            ? null
            : ProcessingJob::query()->find($this->processingJobId);

        if (! config('legalcloud.vector.enabled')) {
            $job?->update([
                'status' => 'failed',
                'progress' => 0,
                'error_code' => 'vector_store_disabled',
                'completed_at' => now(),
            ]);

            return;
        }

        $job?->update(['status' => 'processing', 'progress' => 10, 'started_at' => now()]);

        try {
            DocumentVersion::query()->findOrFail($this->documentVersionId);
            $vectors->forgetVersion($this->documentVersionId);
            DocumentChunk::query()->where('document_version_id', $this->documentVersionId)
                ->orderBy('chunk_index')
                ->chunkById(100, function ($chunks) use ($vectors): void {
                    foreach ($chunks as $chunk) {
                        $vectors->index($chunk);
                    }
                });
            $job?->update(['status' => 'completed', 'progress' => 100, 'completed_at' => now()]);
        } catch (Throwable $exception) {
            $job?->update([
                'status' => 'failed',
                'progress' => 0,
                'error_code' => $this->errorCode($exception),
                'completed_at' => now(),
            ]);
        }
    }

    private function errorCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof EmbeddingProviderNotConfigured, $exception instanceof ProviderNotConfigured => 'embedding_provider_unavailable',
            $exception instanceof EmbeddingProviderFailed => 'embedding_provider_failed',
            default => 'embedding_processing_failed',
        };
    }
}
