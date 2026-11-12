<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Financeiro\Models\InsumoLancamento;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tela Insumos (pedido do Lira em 12/11/2026): material de escritório e
 * galpão lançado à mão, só admin, entrando no custo pela data.
 */
class InsumosControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admin_lanca_edita_e_exclui_e_a_tela_soma_por_categoria(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/insumos', [
            'data' => '2026-11-10', 'descricao' => 'Papel A4', 'categoria' => 'escritorio', 'valor' => 35.9, 'observacao' => 'Kalunga',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/insumos', [
            'data' => '2026-11-11', 'descricao' => 'Lâmpada', 'categoria' => 'galpao', 'valor' => 20,
        ])->assertRedirect();
        $this->actingAs($admin)->post('/admin/insumos', [
            'data' => '2026-10-31', 'descricao' => 'Outro mês', 'categoria' => 'galpao', 'valor' => 500,
        ])->assertRedirect();

        $papel = InsumoLancamento::query()->where('descricao', 'Papel A4')->firstOrFail();
        $this->assertSame($admin->id, $papel->user_id);

        $this->actingAs($admin)->get('/admin/insumos?mes=2026-11')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Financeiro/Insumos', false)
                ->has('lancamentos', 2)
                ->where('total', 55.9)
                ->where('porCategoria.0.categoria', 'escritorio')
                ->where('porCategoria.0.total', 35.9)
                ->where('porCategoria.1.total', 20));

        $this->actingAs($admin)->put("/admin/insumos/{$papel->id}", [
            'data' => '2026-11-10', 'descricao' => 'Papel A4 (caixa)', 'categoria' => 'escritorio', 'valor' => 40,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('insumos_lancamentos', ['id' => $papel->id, 'descricao' => 'Papel A4 (caixa)', 'valor' => 40]);

        $this->actingAs($admin)->delete("/admin/insumos/{$papel->id}")->assertRedirect();
        $this->assertDatabaseMissing('insumos_lancamentos', ['id' => $papel->id]);
    }

    public function test_valida_categoria_e_valor(): void
    {
        $this->actingAs($this->admin())->post('/admin/insumos', [
            'data' => '2026-11-10', 'descricao' => 'X', 'categoria' => 'mercado', 'valor' => 0,
        ])->assertSessionHasErrors(['categoria', 'valor']);

        $this->assertSame(0, InsumoLancamento::query()->count());
    }

    public function test_so_admin_ve_e_lanca(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $insumo = InsumoLancamento::create(['data' => '2026-11-10', 'descricao' => 'Fita', 'categoria' => 'embalagem', 'valor' => 10]);

        $this->actingAs($gerente)->get('/admin/insumos')->assertForbidden();
        $this->actingAs($gerente)->post('/admin/insumos', ['data' => '2026-11-10', 'descricao' => 'X', 'categoria' => 'outro', 'valor' => 5])->assertForbidden();
        $this->actingAs($gerente)->put("/admin/insumos/{$insumo->id}", ['data' => '2026-11-10', 'descricao' => 'X', 'categoria' => 'outro', 'valor' => 5])->assertForbidden();
        $this->actingAs($gerente)->delete("/admin/insumos/{$insumo->id}")->assertForbidden();

        $this->assertSame(1, InsumoLancamento::query()->count());
    }

    public function test_lancamento_entra_no_lucro_pela_data(): void
    {
        $this->actingAs($this->admin())->post('/admin/insumos', [
            'data' => '2026-11-12', 'descricao' => 'Estante do galpão', 'categoria' => 'galpao', 'valor' => 250,
        ])->assertRedirect();

        $cm = app(ContributionMargin::class);
        $dia = $cm->periodo(Carbon::parse('2026-11-12'), Carbon::parse('2026-11-13'));
        $this->assertSame(250.0, $dia['insumos_lancados']);
        $this->assertSame(-250.0, $dia['margem']);
        $this->assertSame(0.0, $cm->periodo(Carbon::parse('2026-11-13'), Carbon::parse('2026-11-14'))['insumos']);
    }
}
