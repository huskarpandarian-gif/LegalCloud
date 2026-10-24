<?php

namespace Tests\Feature;

use App\Domain\AI\Contracts\LlmProvider;
use App\Jobs\ProcessDocumentOcr;
use App\Models\AssistantMessage;
use App\Models\Citation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_login_issues_a_hashed_expiring_bearer_token(): void
    {
        $user = $this->createUser($this->createOrganization(), 'administrator');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ]);

        $response->assertOk()
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonPath('user.id', $user->public_id);

        $plainTextToken = $response->json('accessToken');
        $this->assertNotEmpty($plainTextToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainTextToken),
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['token_hash' => $plainTextToken]);
    }

    public function test_authenticated_user_can_create_and_list_cases_in_their_organization(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization, 'records_manager');
        $token = $this->login($user);

        $this->withToken($token)->postJson('/api/v1/cases', [
            'reference' => 'EXP-2026-001',
            'title' => 'Revisión de normativa',
            'description' => 'Prueba de integración',
        ])->assertCreated()
            ->assertJsonPath('data.reference', 'EXP-2026-001')
            ->assertJsonPath('data.ownerId', $user->public_id);

        $this->withToken($token)->getJson('/api/v1/cases')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Revisión de normativa');
    }

    public function test_case_lookup_does_not_leak_resources_between_organizations(): void
    {
        $organizationA = $this->createOrganization('Org A');
        $organizationB = $this->createOrganization('Org B');
        $user = $this->createUser($organizationA, 'consultant');
        $foreignCase = LegalCase::query()->create([
            'organization_id' => $organizationB->id,
            'reference' => 'EXP-FOREIGN-001',
            'title' => 'Confidential case',
            'status' => 'open',
        ]);

        $this->withToken($this->login($user))
            ->getJson('/api/v1/cases/'.$foreignCase->id)
            ->assertNotFound();
    }

    public function test_consultant_cannot_create_a_case(): void
    {
        $user = $this->createUser($this->createOrganization(), 'consultant');

        $this->withToken($this->login($user))->postJson('/api/v1/cases', [
            'reference' => 'EXP-2026-002',
            'title' => 'No autorizado',
        ])->assertForbidden();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->createUser($this->createOrganization(), 'administrator');
        $token = $this->login($user);
        $tokenHash = hash('sha256', $token);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['token_hash' => $tokenHash]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/cases')->assertUnauthorized();
    }

    public function test_document_upload_stores_a_private_version_and_queues_ocr(): void
    {
        Storage::fake('private');
        Queue::fake();

        $organization = $this->createOrganization();
        $user = $this->createUser($organization, 'records_manager');
        $case = LegalCase::query()->create([
            'organization_id' => $organization->id,
            'reference' => 'EXP-DOC-001',
            'title' => 'Document upload case',
        ]);

        $file = UploadedFile::fake()->create('resolution.pdf', 12, 'application/pdf');
        $response = $this->withToken($this->login($user))->post('/api/v1/documents', [
            'file' => $file,
            'caseId' => $case->id,
            'title' => 'Resolution test',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Resolution test')
            ->assertJsonPath('data.currentVersion.versionNumber', 1);

        $document = Document::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame('Resolution test', $document->title);
        $this->assertNotSame('Resolution test', DB::table('documents')->where('id', $document->id)->value('title'));
        $version = DocumentVersion::query()->firstOrFail();
        Storage::disk('private')->assertExists($version->path);
        $plaintext = Crypt::decryptString(Storage::disk('private')->get($version->path));
        $this->assertSame(hash('sha256', $plaintext), $version->checksum);
        $this->assertSame(file_get_contents($file->getRealPath()), $plaintext);
        Queue::assertPushed(ProcessDocumentOcr::class);
    }

    public function test_assistant_query_is_audited_and_answers_from_authorized_text(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization, 'consultant');
        $case = LegalCase::query()->create([
            'organization_id' => $organization->id,
            'reference' => 'EXP-RAG-001',
            'title' => 'RAG source case',
        ]);
        $this->createIndexedChunk($user, $case, 'La norma establece quince días hábiles para responder.');
        $token = $this->login($user);

        $conversation = $this->withToken($token)->postJson('/api/v1/assistant/conversations', [
            'title' => 'Contratación pública',
        ])->assertCreated()->json('data.id');

        $answer = $this->withToken($token)->postJson("/api/v1/assistant/conversations/{$conversation}/messages", [
            'question' => '¿Qué requisitos establece la norma?',
        ])->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('citations.0.reference', 1);

        $this->assertStringContainsString('quince días hábiles', $answer->json('content'));
        $this->assertStringContainsString('[S1]', $answer->json('content'));

        $storedQuestion = AssistantMessage::query()->where('conversation_id', $conversation)->where('role', 'user')->firstOrFail();
        $this->assertSame('¿Qué requisitos establece la norma?', $storedQuestion->content);
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $organization->id,
            'action' => 'assistant.query',
        ]);
    }

    public function test_assistant_returns_an_answer_with_persisted_authorized_citations(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization, 'consultant');
        $case = LegalCase::query()->create([
            'organization_id' => $organization->id,
            'reference' => 'EXP-RAG-002',
            'title' => 'RAG evidence case',
        ]);
        $document = $this->createIndexedChunk($user, $case, 'La Resolución 118 permite quince días hábiles para responder.');
        $provider = new class implements LlmProvider
        {
            public string $mode = 'answer';

            public function answer(string $question, array $sources, string $mode = 'answer'): string
            {
                if ($sources === []) {
                    throw new \RuntimeException('The test expected retrieved sources.');
                }

                $this->mode = $mode;

                return 'La Resolución 118 permite quince días hábiles [S1].';
            }
        };
        $this->app->instance(LlmProvider::class, $provider);
        $token = $this->login($user);
        $conversation = $this->withToken($token)->postJson('/api/v1/assistant/conversations', [
            'title' => 'Plazos de respuesta',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/v1/assistant/conversations/{$conversation}/messages", [
            'question' => '¿Cuántos días hábiles permite la Resolución 118?',
        ])->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('content', 'La Resolución 118 permite quince días hábiles [S1].')
            ->assertJsonPath('citations.0.reference', 1)
            ->assertJsonPath('citations.0.documentId', $document->id)
            ->assertJsonPath('citations.0.title', 'RAG evidence case');

        $citation = Citation::query()->where('document_id', $document->id)->firstOrFail();
        $this->assertSame('La Resolución 118 permite quince días hábiles para responder.', $citation->excerpt);
        $this->assertSame('answer', $provider->mode);
    }

    public function test_bootstrap_command_creates_an_admin_with_a_hashed_password(): void
    {
        $this->artisan('legalcloud:bootstrap', [
            'organization' => 'Bootstrap Organization',
            'email' => 'initial-admin@example.test',
            '--name' => 'Initial Admin',
        ])->expectsQuestion('Initial administrator password (minimum 12 characters)', 'secure-password-123')
            ->assertSuccessful();

        $user = User::query()->where('email_hash', User::emailLookupHash('initial-admin@example.test'))->firstOrFail();
        $this->assertSame('administrator', $user->role);
        $this->assertSame('initial-admin@example.test', $user->email);
        $this->assertNotSame('initial-admin@example.test', DB::table('users')->where('id', $user->id)->value('email'));
        $this->assertNotSame('secure-password-123', $user->password);
        $this->assertTrue(Hash::check('secure-password-123', $user->password));
        $this->assertNotNull($user->organization_id);
    }

    public function test_dashboard_serializes_tenant_audit_events_with_the_public_contract(): void
    {
        $organization = $this->createOrganization();
        $manager = $this->createUser($organization, 'records_manager');
        $admin = $this->createUser($organization, 'administrator');

        $this->withToken($this->login($manager))->postJson('/api/v1/cases', [
            'reference' => 'EXP-DASH-001',
            'title' => 'Dashboard test case',
        ])->assertCreated();

        Auth::forgetGuards();
        $this->withToken($this->login($admin))->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('cases.total', 1)
            ->assertJsonFragment(['actorId' => $manager->public_id, 'action' => 'case.created']);
    }

    public function test_notifications_are_limited_to_the_authenticated_user(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization, 'consultant');
        $otherUser = $this->createUser($organization, 'consultant');
        UserNotification::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->public_id,
            'type' => 'document_processing',
            'title' => 'OCR completed',
        ]);
        UserNotification::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $otherUser->public_id,
            'type' => 'system',
            'title' => 'Other user notification',
        ]);

        $this->withToken($this->login($user))->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'OCR completed');
    }

    public function test_consultant_only_receives_consultation_data(): void
    {
        $organization = $this->createOrganization();
        $manager = $this->createUser($organization, 'records_manager');
        $consultant = $this->createUser($organization, 'consultant');

        $this->withToken($this->login($manager))->postJson('/api/v1/cases', [
            'reference' => 'EXP-ROLE-001',
            'title' => 'Expediente visible',
        ])->assertCreated();

        Auth::forgetGuards();
        $token = $this->login($consultant);

        $this->withToken($token)->getJson('/api/v1/users')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/audit-events')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('recentActivity', [])
            ->assertJsonPath('cases.total', 1);
        $this->withToken($token)->getJson('/api/v1/cases')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Expediente visible');
    }

    public function test_administrator_can_delete_another_user_but_not_themselves(): void
    {
        $organization = $this->createOrganization();
        $admin = $this->createUser($organization, 'administrator');
        $consultant = $this->createUser($organization, 'consultant');
        $case = LegalCase::query()->create([
            'organization_id' => $organization->id,
            'reference' => 'EXP-DEL-001',
            'title' => 'Owned by consultant',
            'owner_id' => $consultant->public_id,
            'status' => 'open',
        ]);
        $token = $this->login($admin);

        $this->withToken($token)->deleteJson('/api/v1/users/'.$admin->public_id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_delete_self');
        $this->withToken($token)->deleteJson('/api/v1/users/'.$consultant->public_id)->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $consultant->id]);
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $organization->id,
            'action' => 'user.deleted',
            'resource_id' => $consultant->public_id,
        ]);
        $this->assertNull($case->fresh()->owner_id);

        Auth::forgetGuards();
        $otherConsultant = $this->createUser($organization, 'consultant');
        $this->withToken($this->login($otherConsultant))
            ->deleteJson('/api/v1/users/'.$admin->public_id)
            ->assertForbidden();
    }

    public function test_semantic_search_returns_service_unavailable_until_pgvector_is_enabled(): void
    {
        config(['legalcloud.vector.enabled' => false]);
        $user = $this->createUser($this->createOrganization(), 'consultant');

        $this->withToken($this->login($user))->postJson('/api/v1/search', [
            'query' => 'requisitos del procedimiento',
            'mode' => 'semantic',
        ])->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
    }

    private function createOrganization(string $name = 'Test Organization'): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.fake()->unique()->numerify('###'),
        ]);
    }

    private function createUser(Organization $organization, string $role): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'API Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('correct-horse-battery'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function createIndexedChunk(User $user, LegalCase $case, string $content): Document
    {
        $document = Document::query()->create([
            'organization_id' => $user->organization_id,
            'case_id' => $case->id,
            'title' => $case->title,
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
            'status' => 'indexed',
        ]);
        $version = $document->versions()->create([
            'version_number' => 1,
            'file_name' => 'source.pdf',
            'disk' => 'private',
            'path' => 'test/source.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
            'checksum' => hash('sha256', $content),
            'uploaded_by' => $user->public_id,
            'extracted_text' => $content,
        ]);
        $document->update(['current_version_id' => $version->id]);
        DocumentChunk::query()->create([
            'document_version_id' => $version->id,
            'chunk_index' => 0,
            'page' => 2,
            'content' => $content,
        ]);

        return $document;
    }

    private function login(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()->json('accessToken');
    }
}
