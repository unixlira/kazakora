<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_and_edit_their_own_profile(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Ana']);

        $this->actingAs($customer)->get('/perfil')->assertOk();

        $this->actingAs($customer)->put('/perfil', [
            'name' => 'Ana Souza',
            'email' => $customer->email,
        ])->assertRedirect();

        $this->assertSame('Ana Souza', $customer->fresh()->name);
    }

    public function test_customer_cannot_view_another_users_profile(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get("/perfil/usuario/{$other->id}")->assertForbidden();
    }

    public function test_customer_cannot_edit_another_users_profile(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Original']);

        $this->actingAs($customer)->put("/perfil/usuario/{$other->id}", [
            'name' => 'Hacked',
            'email' => $other->email,
        ])->assertForbidden();

        $this->assertSame('Original', $other->fresh()->name);
    }

    public function test_admin_can_view_and_edit_any_users_profile(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Original']);

        $this->actingAs($admin)->get("/perfil/usuario/{$customer->id}")->assertOk();

        $this->actingAs($admin)->put("/perfil/usuario/{$customer->id}", [
            'name' => 'Corrigido pelo admin',
            'email' => $customer->email,
        ])->assertRedirect();

        $this->assertSame('Corrigido pelo admin', $customer->fresh()->name);
    }

    public function test_editing_a_user_is_recorded_in_the_audit_log_with_password_redacted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Original']);

        $this->actingAs($admin)->put("/perfil/usuario/{$customer->id}", [
            'name' => 'Novo nome',
            'email' => $customer->email,
        ]);

        $log = AuditLog::query()->where('entity', 'User')->where('entity_id', $customer->id)->first();

        $this->assertNotNull($log);
        $this->assertSame(AuditLog::ACTION_UPDATE, $log->action);
        $this->assertSame('Novo nome', $log->new_values['name']);
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    public function test_admin_can_soft_delete_a_user_but_not_themselves(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($admin)->delete("/admin/usuarios-permissoes/usuarios/{$customer->id}")->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $customer->id]);

        $this->actingAs($admin)->delete("/admin/usuarios-permissoes/usuarios/{$admin->id}")->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_non_admin_cannot_delete_a_user(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($manager)->delete("/admin/usuarios-permissoes/usuarios/{$customer->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'deleted_at' => null]);
    }
}
