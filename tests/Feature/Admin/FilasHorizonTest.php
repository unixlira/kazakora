<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Filas (Horizon) dentro do admin, só para administrador (2026-10-11). */
class FilasHorizonTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ve_a_tela_de_filas_e_o_horizon(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/filas')->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($admin)->allows('viewHorizon'));
    }

    public function test_cliente_nao_ve_o_horizon(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($cliente)->get('/admin/filas')->assertForbidden();
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($cliente)->allows('viewHorizon'));
    }
}
