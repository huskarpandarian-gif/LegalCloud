<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SpanishApiRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_spanish_session_and_registration_routes(): void
    {
        $response = $this->postJson('/api/v1/auth/registrar', [
            'organization' => 'Bufete Norte',
            'name' => 'Ana Ruiz',
            'email' => 'ana@bufete.test',
            'password' => 'correct-horse-battery',
        ]);

        $response->assertCreated()->assertJsonPath('data.role', 'administrator');

        $this->postJson('/api/v1/auth/iniciar-sesion', [
            'email' => 'ana@bufete.test',
            'password' => 'correct-horse-battery',
        ])->assertOk()->assertJsonPath('user.email', 'ana@bufete.test');
    }

    public function test_roles_categories_and_user_suspension_use_spanish_paths(): void
    {
        $organization = Organization::query()->create(['name' => 'Navarro', 'slug' => 'navarro']);
        $admin = $this->user($organization, 'administrator', 'admin@navarro.test');
        $consultant = $this->user($organization, 'consultant', 'consulta@navarro.test');
        $token = $this->token($admin);

        $this->withToken($token)->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonFragment(['id' => 'records_manager']);

        $this->withToken($token)->getJson('/api/v1/permisos')
            ->assertOk()
            ->assertJsonFragment(['users.manage']);

        $category = $this->withToken($token)->postJson('/api/v1/categorias', [
            'name' => 'Normativa',
            'description' => 'Resoluciones',
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson('/api/v1/categorias/'.$category['id'].'/desactivar')
            ->assertOk()
            ->assertJsonPath('data.active', false);
        $this->assertFalse(Category::query()->find($category['id'])->active);

        $this->withToken($token)->postJson('/api/v1/usuarios/'.$consultant->public_id.'/suspender')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        $this->withToken($token)->postJson('/api/v1/usuarios/'.$consultant->public_id.'/reactivar')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->withToken($token)->putJson('/api/v1/usuarios/'.$consultant->public_id.'/roles', [
            'roles' => ['analyst'],
        ])->assertOk()->assertJsonPath('data.role', 'analyst');
    }

    public function test_workspace_pages_render_the_shell(): void
    {
        $this->get('/expedientes')->assertOk()->assertSee('data-initial-view="cases"', false);
        $this->get('/settings')->assertRedirect('/settings/profile');
        $this->get('/settings/appearance')->assertOk()->assertSee('data-settings-section="appearance"', false);
    }

    private function user(Organization $organization, string $role, string $email): User
    {
        return User::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Usuario de prueba',
            'email' => $email,
            'password' => Hash::make('correct-horse-battery'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/iniciar-sesion', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()->json('accessToken');
    }
}
