<?php

namespace App\Jobs;

use App\Domain\AI\Contracts\OcrProvider;
use App\Domain\AI\OcrExtractionFailed;
use App\Domain\AI\ProviderNotConfigured;
use App\Domain\Documents\DocumentChunker;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\ProcessingJob;
use App\Models\UserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDocumentOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $processingJobId)
    {
        $this->onQueue('document-processing');
    }

    public function handle(OcrProvider $ocr): void
    {
        $job = ProcessingJob::query()->findOrFail($this->processingJobId);
        $version = DocumentVersion::query()->findOrFail($job->document_version_id);
        $job->update(['status' => 'processing', 'progress' => 10, 'started_at' => now()]);
        $temporaryPath = null;

        try {
            $storedContents = Storage::disk($version->disk)->get($version->path);
            $contents = (int) $version->file_encryption_version >= 1
                ? Crypt::decryptString($storedContents)
                : $storedContents;
            $temporaryPath = tempnam(storage_path('app/private'), '.ocr-');
            if ($temporaryPath === false || file_put_contents($temporaryPath, $contents) === false) {
                throw new \RuntimeException('Unable to prepare a private temporary file for OCR.');
            }
            @chmod($temporaryPath, 0600);

            $result = $ocr->extract($temporaryPath, $version->mime_type);
            if (trim($result['text']) === '') {
                throw new OcrExtractionFailed('No se encontró texto extraíble.');
            }
            $version->update(['extracted_text' => $result['text']]);

            DocumentChunk::query()->where('document_version_id', $version->id)->delete();
            foreach ((new DocumentChunker())->fromPages($result['pages']) as $chunk) {
                DocumentChunk::query()->create([
                    'document_version_id' => $version->id,
                    'chunk_index' => $chunk['chunk_index'],
                    'page' => $chunk['page'],
                    'content' => $chunk['content'],
                ]);
            }

            $job->update(['status' => 'completed', 'progress' => 100, 'result_text' => $result['text'], 'completed_at' => now()]);
            Document::query()->whereKey($job->document_id)->update(['status' => 'indexed']);
            $this->notifyUploader($job, $version, 'OCR completado', 'El documento ya se puede consultar.');
            if (config('legalcloud.vector.enabled')) {
                $embeddingJob = ProcessingJob::query()->create([
                    'organization_id' => $job->organization_id,
                    'document_id' => $job->document_id,
                    'document_version_id' => $version->id,
                    'type' => 'embedding',
                    'status' => 'queued',
                    'progress' => 0,
                ]);
                try {
                    IndexDocumentEmbeddings::dispatch($version->id, $embeddingJob->id);
                } catch (Throwable) {
                    // The embedding job records its own failure. OCR text stays indexed.
                }
            }
        } catch (ProviderNotConfigured) {
            $job->update(['status' => 'failed', 'progress' => 0, 'error_code' => 'ocr_provider_unavailable', 'completed_at' => now()]);
            Document::query()->whereKey($job->document_id)->update(['status' => 'failed']);
        } catch (OcrExtractionFailed) {
            $job->update(['status' => 'failed', 'progress' => 0, 'error_code' => 'ocr_no_text', 'completed_at' => now()]);
            Document::query()->whereKey($job->document_id)->update(['status' => 'failed']);
            $this->notifyUploader($job, $version, 'OCR sin texto', 'No se encontró texto extraíble en el documento.');
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $job = ProcessingJob::query()->find($this->processingJobId);
        if (! $job) {
            return;
        }

        $job->update(['status' => 'failed', 'error_code' => 'ocr_processing_failed', 'completed_at' => now()]);
        Document::query()->whereKey($job->document_id)->update(['status' => 'failed']);
    }

    private function notifyUploader(ProcessingJob $job, DocumentVersion $version, string $title, string $body): void
    {
        if (! is_string($version->uploaded_by) || $version->uploaded_by === '') {
            return;
        }

        UserNotification::query()->create([
            'organization_id' => $job->organization_id,
            'user_id' => $version->uploaded_by,
            'type' => 'document_processing',
            'title' => $title,
            'body' => $body,
            'resource_type' => 'document',
            'resource_id' => $job->document_id,
        ]);
    }
}
