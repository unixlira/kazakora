<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Promocao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vender › Promoções (pedido 2026-10-21): Promoção Airfryer e Promoção PDFs
 * guardam as regras que o Lira define no admin.
 */
class PromocoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_as_duas_promocoes_existem_e_abrem(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/promocoes')->assertRedirect('/admin/promocoes/airfryer');
        $this->actingAs($admin)->get('/admin/promocoes/airfryer')->assertOk();
        $this->actingAs($admin)->get('/admin/promocoes/pdfs')->assertOk();
        $this->actingAs($admin)->get('/admin/promocoes/outra')->assertNotFound();
    }

    public function test_salva_regras_e_chaves(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->put('/admin/promocoes/pdfs', [
            'ativa' => true,
            'inicio' => '2026-10-22T08:00',
            'fim' => '2026-10-30T23:59',
            'valor_minimo' => '150.50',
            'observacoes' => 'Brinde em PDF',
            'regras' => [['chave' => 'pedidos_por_cliente', 'valor' => '1']],
        ])->assertSessionHasNoErrors();

        $p = Promocao::query()->where('slug', 'pdfs')->first();
        $this->assertTrue($p->ativa);
        $this->assertSame('150.50', $p->valor_minimo);
        $this->assertSame(['pedidos_por_cliente' => '1'], $p->regras);
        $this->assertFalse(Promocao::query()->where('slug', 'airfryer')->first()->ativa);
    }

    public function test_fim_antes_do_inicio_e_chave_invalida_sao_recusados(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->put('/admin/promocoes/airfryer', [
            'inicio' => '2026-10-30T08:00',
            'fim' => '2026-10-22T08:00',
            'regras' => [['chave' => 'chave inválida', 'valor' => 'x']],
        ])->assertSessionHasErrors(['fim', 'regras.0.chave']);
    }
}
