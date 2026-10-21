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

        // Tela inicial: as duas lado a lado (2026-10-21).
        $this->actingAs($admin)->get('/admin/promocoes')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Promocoes/Index', false)
            ->where('promocoes.0.slug', 'airfryer')
            ->where('promocoes.1.slug', 'pdfs'));
        $this->actingAs($admin)->get('/admin/promocoes/airfryer')->assertOk();
        $this->actingAs($admin)->get('/admin/promocoes/pdfs')->assertOk();
        $this->actingAs($admin)->get('/admin/promocoes/outra')->assertNotFound();
    }

    public function test_adicionar_promocao_cria_card_e_pagina(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/promocoes/nova')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Promocoes/Editar', false)
            ->where('promocao.slug', null));

        $this->actingAs($admin)->post('/admin/promocoes', [
            'nome' => 'Promoção Dia das Crianças',
            'titulo' => 'Brinde no Dia das Crianças',
            'ativa' => true,
            'regras' => [['chave' => 'brinde', 'valor' => 'adesivo']],
        ])->assertRedirect('/admin/promocoes')->assertSessionHasNoErrors();

        $nova = Promocao::query()->where('slug', 'promocao-dia-das-criancas')->firstOrFail();
        $this->assertSame('Promoção Dia das Crianças', $nova->nome);
        $this->assertSame(['brinde' => 'adesivo'], $nova->regras);

        // Mesmo nome de novo ganha -2; o card novo vem depois de Airfryer e PDFs, sem quadro.
        $this->actingAs($admin)->post('/admin/promocoes', ['nome' => 'Promoção Dia das Crianças'])->assertSessionHasNoErrors();
        $this->assertTrue(Promocao::query()->where('slug', 'promocao-dia-das-criancas-2')->exists());

        $this->actingAs($admin)->get('/admin/promocoes')->assertOk()->assertInertia(fn ($page) => $page
            ->where('promocoes.0.slug', 'airfryer')
            ->where('promocoes.1.slug', 'pdfs')
            ->where('promocoes.2.slug', 'promocao-dia-das-criancas')
            ->where('promocoes.2.quadro', false)
            ->where('promocoes.3.slug', 'promocao-dia-das-criancas-2'));

        $this->actingAs($admin)->get('/admin/promocoes/promocao-dia-das-criancas')->assertOk();
        $this->actingAs($admin)->put('/admin/promocoes/promocao-dia-das-criancas', ['nome' => 'Dia das Crianças'])->assertSessionHasNoErrors();
        $this->assertSame('Dia das Crianças', $nova->fresh()->nome);
        $this->get('/promocoes/promocao-dia-das-criancas')->assertOk();
    }

    public function test_promocao_nova_precisa_de_nome(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/promocoes', ['nome' => ''])->assertSessionHasErrors('nome');
        $this->assertSame(2, Promocao::query()->count());
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

    public function test_airfryer_vem_com_o_titulo_e_salva_descricao_com_paragrafos(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->assertSame('Compre, concorra no air fryer', Promocao::query()->where('slug', 'airfryer')->value('titulo'));

        $this->actingAs($admin)->put('/admin/promocoes/airfryer', [
            'titulo' => 'Compre, concorra no air fryer',
            'descricao' => "Primeira linha\r\nsegunda linha\r\n\r\n\r\nOutro parágrafo",
            'ativa' => true,
        ])->assertSessionHasNoErrors();

        $p = Promocao::query()->where('slug', 'airfryer')->first();
        $this->assertSame("Primeira linha\nsegunda linha\n\n\nOutro parágrafo", $p->descricao);
        $this->assertSame(["Primeira linha\nsegunda linha", 'Outro parágrafo'], $p->paragrafos());
    }

    public function test_pagina_publica_mostra_paragrafos_e_some_quando_desativada(): void
    {
        $p = Promocao::query()->where('slug', 'airfryer')->first();
        $p->update(['ativa' => true, 'descricao' => "Linha 1\nLinha 2\n\n<b>Parágrafo 2</b>"]);

        $this->get('/promocoes/airfryer')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Promocoes/Ver', false)
            ->where('promocao.titulo', 'Compre, concorra no air fryer')
            ->where('promocao.paragrafos', ["Linha 1\nLinha 2", '<b>Parágrafo 2</b>'])
            ->where('previa', false));

        $p->update(['ativa' => false]);
        $this->get('/promocoes/airfryer')->assertNotFound();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/promocoes/airfryer')->assertOk()->assertInertia(fn ($page) => $page->where('previa', true));
    }

    public function test_pagina_publica_some_depois_do_fim(): void
    {
        Promocao::query()->where('slug', 'airfryer')->update(['ativa' => true, 'fim' => now()->subMinute()]);

        $this->get('/promocoes/airfryer')->assertNotFound();
    }
}
