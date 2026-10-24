<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\OcrProvider;
use App\Domain\Audit\AuditLogger;
use App\Jobs\ProcessDocumentOcr;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SensitiveDataEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ocr_chunk_is_ciphertext_at_rest_and_searchable_after_decryption(): void
    {
        [$user, $document, $version, $chunk] = $this->createDocument('La resolución fija quince días hábiles.');
        $storedContent = DB::table('document_chunks')->where('id', $chunk->id)->value('content');

        $this->assertNotSame($chunk->content, $storedContent);
        $this->assertSame('La resolución fija quince días hábiles.', $chunk->fresh()->content);

        $token = $this->login($user);
        $this->withToken($token)->postJson('/api/v1/search', [
            'query' => 'quince días',
            'mode' => 'keyword',
        ])->assertOk()
            ->assertJsonPath('data.0.document.id', $document->id)
            ->assertJsonPath('data.0.excerpt', 'La resolución fija quince días hábiles.');
    }

    public function test_download_is_authenticated_and_returns_decrypted_file_without_caching(): void
    {
        Storage::fake('private');
        [$user, $document, $version] = $this->createDocument();
        $plaintext = '%PDF-1.7 private legal document';
        Storage::disk('private')->put($version->path, Crypt::encryptString($plaintext));

        $response = $this->withToken($this->login($user))->get(
            "/api/v1/documents/{$document->id}/versions/{$version->id}/download",
        );

        $response->assertOk()
            ->assertContent($plaintext)
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->withHeader('Accept', 'application/json')
            ->get("/api/v1/documents/{$document->id}/versions/{$version->id}/download")
            ->assertUnauthorized();
    }

    public function test_ocr_receives_plaintext_from_an_encrypted_file_and_removes_its_temp_copy(): void
    {
        Storage::fake('private');
        [, $document, $version] = $this->createDocument();
        $plaintextFile = '%PDF-1.7 encrypted before OCR';
        Storage::disk('private')->put($version->path, Crypt::encryptString($plaintextFile));
        $processingJob = ProcessingJob::query()->create([
            'organization_id' => $document->organization_id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'type' => 'ocr',
            'status' => 'queued',
        ]);
        $provider = new class implements OcrProvider
        {
            public string $receivedContents = '';

            public string $temporaryPath = '';

            public function extract(string $path, string $mimeType): array
            {
                $this->temporaryPath = $path;
                $this->receivedContents = file_get_contents($path);

                return ['text' => 'El documento permite quince días.', 'pages' => ['El documento permite quince días.']];
            }
        };

        (new ProcessDocumentOcr($processingJob->id))->handle($provider);

        $this->assertSame($plaintextFile, $provider->receivedContents);
        $this->assertFileDoesNotExist($provider->temporaryPath);
        $this->assertSame('El documento permite quince días.', $version->fresh()->extracted_text);
        $chunk = DocumentChunk::query()->firstOrFail();
        $encryptedChunk = DB::table('document_chunks')->where('id', $chunk->id)->value('content');
        $this->assertNotSame($chunk->content, $encryptedChunk);
        $this->assertSame('El documento permite quince días.', $chunk->content);
    }

    public function test_sensitive_metadata_is_encrypted_at_rest_and_remains_searchable(): void
    {
        [$user, $document, $version] = $this->createDocument();
        $case = $document->legalCase;

        $this->assertNotSame($case->reference, DB::table('legal_cases')->where('id', $case->id)->value('reference'));
        $this->assertNotSame($case->title, DB::table('legal_cases')->where('id', $case->id)->value('title'));
        $this->assertNotSame($document->title, DB::table('documents')->where('id', $document->id)->value('title'));
        $this->assertNotSame($version->file_name, DB::table('document_versions')->where('id', $version->id)->value('file_name'));

        $token = $this->login($user);
        $this->withToken($token)->getJson('/api/v1/cases?search=Encryption')
            ->assertOk()->assertJsonPath('data.0.title', 'Encryption evidence');
        $this->withToken($token)->getJson('/api/v1/cases?search='.$case->reference)
            ->assertOk()->assertJsonPath('data.0.reference', $case->reference);
        $this->withToken($token)->postJson('/api/v1/search', [
            'query' => 'Encryption evidence',
            'mode' => 'keyword',
        ])->assertOk()->assertJsonPath('data.0.document.title', 'Encryption evidence');
    }

    public function test_notification_body_and_audit_payload_are_encrypted_at_rest(): void
    {
        [$user] = $this->createDocument();
        $notification = UserNotification::query()->create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->public_id,
            'type' => 'document_processing',
            'title' => 'Confidential file processing',
            'body' => 'The confidential attachment finished OCR.',
        ]);
        $audit = app(AuditLogger::class)->record(
            Request::create('/api/v1/cases', 'POST'),
            $user,
            'case.updated',
            'case',
            null,
            ['title' => 'Confidential case title'],
        );

        $this->assertNotSame($notification->title, DB::table('notifications')->where('id', $notification->id)->value('title'));
        $this->assertNotSame($notification->body, DB::table('notifications')->where('id', $notification->id)->value('body'));
        $this->assertSame('Confidential file processing', $notification->fresh()->title);
        $this->assertSame('The confidential attachment finished OCR.', $notification->fresh()->body);
        $this->assertNull(DB::table('audit_events')->where('id', $audit->id)->value('ip_address'));
        $this->assertNotNull(DB::table('audit_events')->where('id', $audit->id)->value('encrypted_ip_address'));
        $this->assertSame(['title' => 'Confidential case title'], $audit->fresh()->changes);
    }

    public function test_conversation_titles_and_ocr_job_results_are_encrypted_at_rest(): void
    {
        [$user, $document, $version] = $this->createDocument();
        $conversation = Conversation::query()->create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'title' => 'Confidential legal question',
        ]);
        $job = ProcessingJob::query()->create([
            'organization_id' => $user->organization_id,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'type' => 'ocr',
            'status' => 'completed',
            'result_text' => 'Privileged extracted text',
        ]);

        $this->assertNotSame('Confidential legal question', DB::table('conversations')->where('id', $conversation->id)->value('title'));
        $this->assertNotSame('Privileged extracted text', DB::table('processing_jobs')->where('id', $job->id)->value('result_text'));
        $this->assertSame('Confidential legal question', $conversation->fresh()->title);
        $this->assertSame('Privileged extracted text', $job->fresh()->result_text);
    }

    public function test_legacy_encryption_backfill_encrypts_files_and_text_idempotently(): void
    {
        Storage::fake('private');
        [, , $version, $chunk] = $this->createDocument('Legacy chunk plaintext');
        $legacyFile = '%PDF legacy plaintext';
        Storage::disk('private')->put($version->path, $legacyFile);
        DB::table('document_versions')->where('id', $version->id)->update([
            'file_name' => 'legacy-name.pdf',
            'file_name_encryption_version' => 0,
            'file_encryption_version' => 0,
            'extracted_text' => 'Legacy OCR plaintext',
            'content_encryption_version' => 0,
        ]);
        DB::table('document_chunks')->where('id', $chunk->id)->update([
            'content' => 'Legacy chunk plaintext',
            'encryption_version' => 0,
        ]);

        $this->artisan('legalcloud:encrypt-sensitive-data')->assertSuccessful();

        $encryptedVersion = $version->fresh();
        $this->assertSame('legacy-name.pdf', $encryptedVersion->file_name);
        $this->assertSame('Legacy OCR plaintext', $encryptedVersion->extracted_text);
        $this->assertSame($legacyFile, Crypt::decryptString(Storage::disk('private')->get($encryptedVersion->path)));
        $this->assertSame('Legacy chunk plaintext', $chunk->fresh()->content);
        $this->assertNotSame('Legacy chunk plaintext', DB::table('document_chunks')->where('id', $chunk->id)->value('content'));

        $this->artisan('legalcloud:encrypt-sensitive-data')->assertSuccessful();
        $this->assertSame('Legacy OCR plaintext', $encryptedVersion->fresh()->extracted_text);
    }

    /** @return array{User, Document, DocumentVersion, DocumentChunk|null} */
    private function createDocument(?string $chunkText = null): array
    {
        $organization = Organization::query()->create(['name' => 'Encryption Test', 'slug' => 'encryption-'.fake()->unique()->numerify('###')]);
        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Encryption User',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('correct-horse-battery'),
            'role' => 'records_manager',
            'status' => 'active',
        ]);
        $case = LegalCase::query()->create([
            'organization_id' => $organization->id,
            'reference' => 'ENC-'.fake()->unique()->numerify('#####'),
            'title' => 'Encryption evidence',
            'status' => 'open',
        ]);
        $document = Document::query()->create([
            'organization_id' => $organization->id,
            'case_id' => $case->id,
            'title' => 'Encryption evidence',
            'mime_type' => 'application/pdf',
            'size_bytes' => 64,
            'status' => 'indexed',
        ]);
        $version = $document->versions()->create([
            'version_number' => 1,
            'file_name' => 'evidence.pdf',
            'disk' => 'private',
            'path' => "organizations/{$organization->id}/encrypted.enc",
            'mime_type' => 'application/pdf',
            'size_bytes' => 64,
            'checksum' => hash('sha256', 'encrypted test file'),
            'uploaded_by' => $user->public_id,
            'file_encryption_version' => 1,
        ]);
        $document->update(['current_version_id' => $version->id]);
        $chunk = $chunkText === null ? null : DocumentChunk::query()->create([
            'document_version_id' => $version->id,
            'chunk_index' => 0,
            'page' => 1,
            'content' => $chunkText,
        ]);

        return [$user, $document, $version, $chunk];
    }

    private function login(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()->json('accessToken');
    }
}
