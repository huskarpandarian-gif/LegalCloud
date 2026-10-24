<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\OcrProvider;
use App\Jobs\ProcessDocumentOcr;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\ProcessingJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NativeTextExtractionPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_digital_pdf_becomes_an_indexed_chunk_without_an_external_ocr_provider(): void
    {
        Storage::fake('private');
        config(['legalcloud.vector.enabled' => false, 'legalcloud.ocr.provider' => 'native']);
        [$document, $version] = $this->storeEncryptedPdf(<<<'PDF'
            %PDF-1.1
            1 0 obj
            << /Length 68 >>
            stream
            BT
            (Quince dias habiles) Tj
            ET
            endstream
            endobj
            %%EOF
            PDF);
        $job = ProcessingJob::query()->create([
            'organization_id' => $document->organization_id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'type' => 'ocr',
            'status' => 'queued',
        ]);

        (new ProcessDocumentOcr($job->id))->handle(app(OcrProvider::class));

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('indexed', $document->fresh()->status);
        $this->assertStringContainsString('Quince dias habiles', $version->fresh()->extracted_text);
        $this->assertSame('Quince dias habiles', DocumentChunk::query()->firstOrFail()->content);
        $this->assertSame(0, ProcessingJob::query()->where('type', 'embedding')->count());
    }

    public function test_an_image_keeps_the_ocr_job_failed_until_an_image_provider_exists(): void
    {
        Storage::fake('private');
        [$document, $version] = $this->storeEncryptedPdf('PNG-bytes', 'image/png');
        $job = ProcessingJob::query()->create([
            'organization_id' => $document->organization_id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'type' => 'ocr',
            'status' => 'queued',
        ]);

        (new ProcessDocumentOcr($job->id))->handle(app(OcrProvider::class));

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('ocr_provider_unavailable', $job->fresh()->error_code);
        $this->assertSame('failed', $document->fresh()->status);
        $this->assertSame(0, DocumentChunk::query()->count());
    }

    /** @return array{Document, DocumentVersion} */
    private function storeEncryptedPdf(string $contents, string $mimeType = 'application/pdf'): array
    {
        $organization = Organization::query()->create([
            'name' => 'Extraction Test',
            'slug' => 'extract-'.fake()->unique()->numerify('###'),
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Extraction User',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('correct-horse-battery'),
            'role' => 'records_manager',
            'status' => 'active',
        ]);
        $case = LegalCase::query()->create([
            'organization_id' => $organization->id,
            'reference' => 'EXT-'.fake()->unique()->numerify('#####'),
            'title' => 'Extraction case',
            'status' => 'open',
        ]);
        $document = Document::query()->create([
            'organization_id' => $organization->id,
            'case_id' => $case->id,
            'title' => 'Extraction document',
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
            'status' => 'processing',
        ]);
        $path = "organizations/{$organization->id}/source.enc";
        Storage::disk('private')->put($path, Crypt::encryptString($contents));
        $version = $document->versions()->create([
            'version_number' => 1,
            'file_name' => 'source.pdf',
            'disk' => 'private',
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'uploaded_by' => $user->public_id,
            'file_encryption_version' => 1,
        ]);
        $document->update(['current_version_id' => $version->id]);

        return [$document, $version];
    }
}
