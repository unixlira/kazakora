<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscriber_can_view_categories_but_not_create_them(): void
    {
        $subscriber = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);

        $this->actingAs($subscriber)->get('/admin/categorias')->assertOk();
        $this->actingAs($subscriber)->post('/admin/categorias', ['name' => 'Nova'])->assertForbidden();
    }

    public function test_manager_can_create_and_edit_but_not_delete_categories(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $category = Category::factory()->create();

        $this->actingAs($manager)->post('/admin/categorias', ['name' => 'Nova Categoria'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['name' => 'Nova Categoria']);

        $this->actingAs($manager)->put("/admin/categorias/{$category->id}", ['name' => 'Renomeada'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Renomeada']);

        $this->actingAs($manager)->delete("/admin/categorias/{$category->id}")->assertForbidden();
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_admin_bypasses_the_permission_matrix_entirely(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $category = Category::factory()->create();

        $this->actingAs($admin)->delete("/admin/categorias/{$category->id}")->assertRedirect();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_customer_cannot_reach_the_admin_panel_at_all(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_only_admin_can_access_usuarios_permissoes_and_auditoria(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($manager)->get('/admin/usuarios-permissoes')->assertForbidden();
        $this->actingAs($manager)->get('/admin/auditoria')->assertForbidden();

        $this->actingAs($admin)->get('/admin/usuarios-permissoes')->assertOk();
        $this->actingAs($admin)->get('/admin/auditoria')->assertOk();
    }

    public function test_staff_actions_are_recorded_in_the_audit_log(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)->post('/admin/categorias', ['name' => 'Categoria Auditada'])->assertRedirect();

        $category = Category::query()->where('name', 'Categoria Auditada')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->id,
            'action' => AuditLog::ACTION_CREATE,
            'entity' => 'Category',
            'entity_id' => $category->id,
        ]);
    }

    public function test_customer_checkout_actions_are_not_audited(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->post('/admin/categorias', ['name' => 'x']);

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_admin_can_change_a_users_role_and_edit_the_permission_matrix(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $target = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);

        $this->actingAs($admin)
            ->patch("/admin/usuarios-permissoes/usuarios/{$target->id}", ['role' => User::ROLE_MANAGER])
            ->assertRedirect();

        $this->assertSame(User::ROLE_MANAGER, $target->fresh()->role);

        $this->actingAs($admin)
            ->put('/admin/usuarios-permissoes/matriz', [
                'role' => User::ROLE_SUBSCRIBER,
                'permissions' => ['cadastros.view' => true, 'cadastros.create' => true],
            ])
            ->assertRedirect();

        $subscriber = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        $this->actingAs($subscriber)->post('/admin/categorias', ['name' => 'Agora Pode'])->assertRedirect();
    }
}
