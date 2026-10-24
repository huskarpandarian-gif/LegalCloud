<?php

namespace Tests\Feature;

use App\Domain\Identity\ApiTokenService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_only_receives_its_default_operations(): void
    {
        $organization = Organization::query()->create(['name' => 'Navarro', 'slug' => 'navarro-roles']);
        $admin = $this->user($organization, 'administrator');
        $manager = $this->user($organization, 'records_manager');
        $analyst = $this->user($organization, 'analyst');
        $consultant = $this->user($organization, 'consultant');

        $this->withToken($this->token($admin))->postJson('/api/v1/expedientes', [
            'reference' => 'EXP-ADMIN',
            'title' => 'Expediente del administrador',
        ])->assertCreated();
        $this->withToken($this->token($admin))->postJson('/api/v1/documentos/archivo')->assertUnprocessable();
        $this->withToken($this->token($admin))->postJson('/api/v1/conversaciones', ['title' => 'Consulta'])->assertCreated();
        $this->withToken($this->token($admin))->postJson('/api/v1/categorias', ['name' => 'Normativa'])->assertCreated();
        $this->withToken($this->token($admin))->getJson('/api/v1/audit-events')->assertOk();
        $this->withToken($this->token($admin))->getJson('/api/v1/usuarios')->assertOk();

        Auth::forgetGuards();
        $this->withToken($this->token($manager))->postJson('/api/v1/expedientes', [
            'reference' => 'EXP-RM',
            'title' => 'Expediente del responsable',
        ])->assertCreated();
        $this->withToken($this->token($manager))->postJson('/api/v1/categorias', ['name' => 'Otra'])->assertForbidden();
        $this->withToken($this->token($manager))->getJson('/api/v1/audit-events')->assertOk();
        $this->withToken($this->token($manager))->postJson('/api/v1/conversaciones', ['title' => 'Consulta'])->assertCreated();
        $this->withToken($this->token($manager))->getJson('/api/v1/usuarios')->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($this->token($analyst))->postJson('/api/v1/expedientes', [
            'reference' => 'EXP-ANALYST',
            'title' => 'Expediente del analista',
        ])->assertCreated();
        $this->withToken($this->token($analyst))->postJson('/api/v1/categorias', ['name' => 'Analista'])->assertForbidden();
        $this->withToken($this->token($analyst))->getJson('/api/v1/audit-events')->assertForbidden();
        $this->withToken($this->token($analyst))->postJson('/api/v1/conversaciones', ['title' => 'Consulta'])->assertCreated();

        Auth::forgetGuards();
        $this->withToken($this->token($consultant))->postJson('/api/v1/expedientes', [
            'reference' => 'EXP-CONSULTANT',
            'title' => 'No autorizado',
        ])->assertForbidden();
        $this->withToken($this->token($consultant))->postJson('/api/v1/documentos/archivo')->assertForbidden();
        $this->withToken($this->token($consultant))->postJson('/api/v1/categorias', ['name' => 'Consulta'])->assertForbidden();
        $this->withToken($this->token($consultant))->getJson('/api/v1/audit-events')->assertForbidden();
        $this->withToken($this->token($consultant))->postJson('/api/v1/conversaciones', ['title' => 'Consulta'])->assertCreated();
        $this->withToken($this->token($consultant))->getJson('/api/v1/expedientes')->assertOk();
    }

    public function test_login_returns_the_permissions_of_the_stored_role(): void
    {
        $organization = Organization::query()->create(['name' => 'Navarro', 'slug' => 'navarro-login']);
        $manager = $this->user($organization, 'records_manager');

        $this->postJson('/api/v1/auth/iniciar-sesion', [
            'email' => 'records@navarro.test',
            'password' => 'correct-horse-battery',
        ])->assertOk()
            ->assertJsonPath('user.role', 'records_manager')
            ->assertJsonPath('user.permissions', [
                'cases.read',
                'cases.write',
                'documents.read',
                'documents.write',
                'documents.process',
                'categories.read',
                'search.use',
                'assistant.use',
                'audit.read',
                'notifications.read',
            ]);

        $this->assertSame($manager->public_id, User::query()->where('role', 'records_manager')->firstOrFail()->public_id);
    }

    private function user(Organization $organization, string $role): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Usuario '.$role,
            'email' => $role === 'records_manager' ? 'records@navarro.test' : $role.'@navarro.test',
            'password' => Hash::make('correct-horse-battery'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function token(User $user): string
    {
        return app(ApiTokenService::class)->issue($user)['plainTextToken'];
    }
}
