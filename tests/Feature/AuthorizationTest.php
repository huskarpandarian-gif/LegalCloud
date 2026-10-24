<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\LegalCase;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_sends_each_role_to_resumen_with_its_permissions(): void
    {
        $organization = $this->organization();
        $expectations = [
            'administrator' => ['categories.manage', 'users.manage', 'cases.write', 'documents.process', 'assistant.use'],
            'records_manager' => ['documents.process', 'documents.write', 'cases.write', 'assistant.use', 'audit.read'],
            'analyst' => ['categories.read', 'documents.write', 'cases.write', 'assistant.use'],
            'consultant' => ['documents.read', 'categories.read', 'assistant.use'],
        ];

        foreach ($expectations as $role => $permissions) {
            $user = $this->user($organization, $role);
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'correct-horse-battery',
            ])->assertOk()->assertJsonPath('home', '/dashboard');

            $granted = $response->json('user.permissions');
            foreach ($permissions as $permission) {
                $this->assertContains($permission, $granted);
            }
        }

        $consultant = User::query()->where('role', 'consultant')->firstOrFail();
        $consultantPermissions = $this->postJson('/api/v1/auth/login', [
            'email' => $consultant->email,
            'password' => 'correct-horse-battery',
        ])->json('user.permissions');
        $this->assertNotContains('categories.manage', $consultantPermissions);
        $this->assertNotContains('documents.write', $consultantPermissions);
    }

    public function test_each_role_is_allowed_or_denied_on_protected_operations(): void
    {
        Queue::fake();
        Storage::fake('private');
        $organization = $this->organization();
        $users = [
            'administrator' => $this->user($organization, 'administrator'),
            'records_manager' => $this->user($organization, 'records_manager'),
            'analyst' => $this->user($organization, 'analyst'),
            'consultant' => $this->user($organization, 'consultant'),
        ];
        $case = LegalCase::query()->create([
            'organization_id' => $organization->id,
            'reference' => 'EXP-AUTH-001',
            'title' => 'Expediente visible',
            'status' => 'open',
        ]);
        $document = $this->document($organization, $case, 'Texto autorizado del expediente.');

        $tokens = [];
        foreach ($users as $role => $user) {
            $tokens[$role] = $this->token($user);
        }

        $this->bearer($tokens['consultant'])->postJson('/api/v1/cases', [
            'reference' => 'EXP-NO',
            'title' => 'No autorizado',
        ])->assertForbidden();
        $this->bearer($tokens['consultant'])->postJson('/api/v1/documentos/archivo', [
            'caseId' => $case->id,
            'title' => 'No',
        ])->assertForbidden();
        $this->bearer($tokens['consultant'])->postJson('/api/v1/categorias', [
            'name' => 'Secreto',
        ])->assertForbidden();
        $this->bearer($tokens['consultant'])->postJson('/api/v1/documentos/'.$document->id.'/versiones/'.$document->current_version_id.'/procesamiento')->assertForbidden();
        $this->bearer($tokens['consultant'])->getJson('/api/v1/usuarios')->assertForbidden();
        $this->bearer($tokens['consultant'])->getJson('/api/v1/audit-events')->assertForbidden();
        $this->bearer($tokens['consultant'])->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonMissingPath('processing');

        $this->bearer($tokens['analyst'])->postJson('/api/v1/categorias', [
            'name' => 'Analista',
        ])->assertForbidden();
        $this->bearer($tokens['analyst'])->postJson('/api/v1/cases', [
            'reference' => 'EXP-ANALYST',
            'title' => 'Creado por analista',
        ])->assertCreated();
        $this->bearer($tokens['analyst'])->postJson('/api/v1/conversaciones', [
            'title' => 'Consulta del analista',
        ])->assertCreated();

        $this->bearer($tokens['records_manager'])->postJson('/api/v1/categorias', [
            'name' => 'Documental',
        ])->assertForbidden();
        $this->bearer($tokens['records_manager'])->postJson('/api/v1/documentos/'.$document->id.'/versiones/'.$document->current_version_id.'/procesamiento')
            ->assertStatus(202);
        $this->bearer($tokens['records_manager'])->getJson('/api/v1/audit-events')->assertOk();
        $this->bearer($tokens['records_manager'])->postJson('/api/v1/conversaciones', ['title' => 'Consulta documental'])->assertCreated();
        $this->bearer($tokens['records_manager'])->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['processing' => ['queued', 'failed']]);

        $category = $this->bearer($tokens['administrator'])->postJson('/api/v1/categorias', [
            'name' => 'Normativa',
        ])->assertCreated()->json('data');
        $this->bearer($tokens['administrator'])->postJson('/api/v1/categorias/'.$category['id'].'/desactivar')
            ->assertOk()
            ->assertJsonPath('data.active', false);
        $this->bearer($tokens['records_manager'])->patchJson('/api/v1/categorias/'.$category['id'], [
            'name' => 'Cambio no permitido',
        ])->assertForbidden();

        foreach ($tokens as $token) {
            $this->bearer($token)->getJson('/api/v1/categorias')
                ->assertOk()
                ->assertJsonFragment(['name' => 'Normativa']);
            $this->bearer($token)->getJson('/api/v1/cases')
                ->assertOk()
                ->assertJsonFragment(['title' => 'Expediente visible']);
        }
    }

    public function test_downloads_search_and_assistant_stay_inside_authorized_documents(): void
    {
        Storage::fake('private');
        $organization = $this->organization();
        $other = $this->organization('Otra organización');
        $consultant = $this->user($organization, 'consultant');
        $case = LegalCase::query()->create([
            'organization_id' => $organization->id,
            'reference' => 'EXP-OWN',
            'title' => 'Propio',
            'status' => 'open',
        ]);
        $foreignCase = LegalCase::query()->create([
            'organization_id' => $other->id,
            'reference' => 'EXP-FOREIGN',
            'title' => 'Ajeno confidencial',
            'status' => 'open',
        ]);
        $own = $this->document($organization, $case, 'Plazo propio de quince días hábiles.');
        $foreign = $this->document($other, $foreignCase, 'Clave ajena zeta-9 que no debe citarse.');
        $token = $this->token($consultant);

        $this->withToken($token)->get('/api/v1/documentos/'.$own->id.'/versiones/'.$own->current_version_id.'/descarga')
            ->assertOk()
            ->assertSee('archivo-autorizado');
        $this->withToken($token)->getJson('/api/v1/documentos/'.$foreign->id.'/versiones/'.$foreign->current_version_id.'/descarga')
            ->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/documentos/'.$foreign->id.'/versiones/'.$foreign->current_version_id.'/texto')
            ->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/cases/'.$foreignCase->id)->assertNotFound();

        $this->withToken($token)->postJson('/api/v1/search', [
            'query' => 'zeta-9',
            'mode' => 'keyword',
        ])->assertOk()->assertJsonPath('meta.total', 0);

        $this->withToken($token)->postJson('/api/v1/search', [
            'query' => 'quince días',
            'mode' => 'keyword',
        ])->assertOk()->assertJsonPath('data.0.document.id', $own->id);

        $conversation = $this->withToken($token)->postJson('/api/v1/conversaciones', [
            'title' => 'Consulta',
        ])->assertCreated()->json('data.id');
        $answer = $this->withToken($token)->postJson('/api/v1/conversaciones/'.$conversation.'/mensajes', [
            'question' => '¿Qué dice el documento sobre zeta-9?',
            'documentIds' => [$foreign->id],
        ]);
        $answer->assertStatus(422);

        $allowed = $this->withToken($token)->postJson('/api/v1/conversaciones/'.$conversation.'/mensajes', [
            'question' => '¿Qué plazo propio aparece?',
        ])->assertOk();
        $this->assertStringNotContainsString('zeta-9', (string) $allowed->json('content'));
        $this->assertNotSame($foreign->id, $allowed->json('citations.0.documentId'));
    }

    private function organization(string $name = 'Organización'): Organization
    {
        return Organization::query()->create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.fake()->unique()->numerify('###'),
        ]);
    }

    private function user(Organization $organization, string $role): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Persona '.$role,
            'email' => $role.'-'.fake()->unique()->numerify('###').'@example.test',
            'password' => Hash::make('correct-horse-battery'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function bearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()->json('accessToken');
    }

    private function document(Organization $organization, LegalCase $case, string $content): Document
    {
        $document = Document::query()->create([
            'organization_id' => $organization->id,
            'case_id' => $case->id,
            'title' => $case->title,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'status' => 'indexed',
        ]);
        $path = 'organizations/'.$organization->id.'/'.$document->id.'.enc';
        Storage::disk('private')->put($path, Crypt::encryptString('archivo-autorizado'));
        $version = $document->versions()->create([
            'version_number' => 1,
            'file_name' => 'fuente.txt',
            'disk' => 'private',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'checksum' => hash('sha256', $content),
            'uploaded_by' => null,
            'extracted_text' => $content,
            'file_encryption_version' => 1,
        ]);
        $document->update(['current_version_id' => $version->id]);
        DocumentChunk::query()->create([
            'document_version_id' => $version->id,
            'chunk_index' => 0,
            'page' => 1,
            'content' => $content,
        ]);

        return $document->refresh();
    }
}
