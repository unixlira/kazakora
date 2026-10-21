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

    public function test_cria_promocao_nova_pela_lista(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/promocoes', ['nome' => 'Promoção Black Friday'])
            ->assertRedirect('/admin/promocoes/promocao-black-friday');
        // Mesmo nome de novo ganha outro endereço; nome reservado também.
        $this->actingAs($admin)->post('/admin/promocoes', ['nome' => 'Promoção Black Friday'])
            ->assertRedirect('/admin/promocoes/promocao-black-friday-2');
        $this->actingAs($admin)->post('/admin/promocoes', ['nome' => 'Nova'])->assertRedirect('/admin/promocoes/nova-2');
        $this->actingAs($admin)->post('/admin/promocoes', ['nome' => ''])->assertSessionHasErrors('nome');

        $p = Promocao::query()->where('slug', 'promocao-black-friday')->first();
        $this->assertSame('Promoção Black Friday', $p->nome);
        $this->assertFalse($p->ativa);

        // Aparece na lista, abre, salva e a página pública segue a mesma regra.
        $this->actingAs($admin)->get('/admin/promocoes')->assertOk()->assertInertia(fn ($page) => $page
            ->where('promocoes.0.slug', 'airfryer')
            ->has('outras', 3)
            ->where('outras.2.slug', 'promocao-black-friday')
            ->where('outras.2.regras', 0)
            ->where('outras.2.valor_minimo', null));
        $this->actingAs($admin)->get('/admin/promocoes/promocao-black-friday')->assertOk();
        $this->actingAs($admin)->put('/admin/promocoes/promocao-black-friday', ['titulo' => 'Black Friday', 'ativa' => true])
            ->assertSessionHasNoErrors();
        auth()->logout();
        $this->get('/promocoes/promocao-black-friday')->assertOk()->assertInertia(fn ($page) => $page->where('promocao.titulo', 'Black Friday'));
    }

    public function test_cliente_nao_cria_promocao(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($cliente)->post('/admin/promocoes', ['nome' => 'Invasora']);
        $this->assertFalse(Promocao::query()->where('nome', 'Invasora')->exists());
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

    public function test_salva_regras_de_negocio_e_mostra_ao_cliente(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->put('/admin/promocoes/pdfs', [
            'titulo' => 'Ganhe os PDFs',
            'ativa' => true,
            'inicio' => now()->subDay()->format('Y-m-d\\TH:i'),
            'fim' => '2099-12-31T23:59',
            'valor_minimo' => '99.90',
            'itens_minimos' => 2,
            'limite_por_cliente' => 1,
            'primeira_compra' => true,
            'acumula_cupom' => false,
        ])->assertSessionHasNoErrors();

        $p = Promocao::query()->where('slug', 'pdfs')->first();
        $this->assertSame(2, $p->itens_minimos);
        $this->assertSame(1, $p->limite_por_cliente);
        $this->assertTrue($p->primeira_compra);
        $this->assertFalse($p->acumula_cupom);

        $this->actingAs($admin)->get('/admin/promocoes/pdfs')->assertInertia(fn ($page) => $page
            ->component('Admin/Promocoes/Editar', false)
            ->where('promocao.itens_minimos', 2)
            ->where('promocao.acumula_cupom', false));

        auth()->logout();
        $this->get('/promocoes/pdfs')->assertOk()->assertInertia(fn ($page) => $page
            ->where('promocao.regras.1', 'Pedido mínimo de R$ 99,90.')
            ->where('promocao.regras.2', 'Mínimo de 2 itens no pedido.')
            ->where('promocao.regras.3', 'Uma participação por cliente.')
            ->where('promocao.regras.4', 'Só na primeira compra na loja.')
            ->where('promocao.regras.5', 'Não vale junto com cupom de desconto.'));

        // O que falta para um pedido entrar.
        $this->assertSame([], $p->pendencias(100, 2));
        $this->assertCount(5, $p->pendencias(50, 1, 1, false, true));
    }

    public function test_sem_os_campos_novos_a_promocao_continua_acumulando_cupom(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->put('/admin/promocoes/airfryer', ['ativa' => true, 'itens_minimos' => 0])
            ->assertSessionHasErrors('itens_minimos');
        $this->actingAs($admin)->put('/admin/promocoes/airfryer', ['ativa' => true])->assertSessionHasNoErrors();

        $p = Promocao::query()->where('slug', 'airfryer')->first();
        $this->assertTrue($p->acumula_cupom);
        $this->assertFalse($p->primeira_compra);
        $this->assertNull($p->itens_minimos);
        $this->assertSame([], $p->regrasParaCliente());
    }

    public function test_pagina_publica_vem_do_cache_e_atualiza_ao_salvar(): void
    {
        $p = Promocao::query()->where('slug', 'airfryer')->first();
        $p->update(['ativa' => true, 'titulo' => 'Antes']);
        $this->get('/promocoes/airfryer')->assertOk();
        // Só as colunas: o cache da produção não desserializa objetos.
        $this->assertIsArray(\Illuminate\Support\Facades\Cache::get('promocao:v2:airfryer'));

        // Na segunda visita não vai ao banco atrás da promoção.
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->get('/promocoes/airfryer')->assertOk();
        $this->assertEmpty(array_filter(
            \Illuminate\Support\Facades\DB::getQueryLog(),
            fn (array $q) => str_contains($q['query'], 'from `promocoes`'),
        ));

        // Salvar pelo admin limpa o cache na hora.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->put('/admin/promocoes/airfryer', ['titulo' => 'Depois', 'ativa' => true]);
        auth()->logout();
        $this->get('/promocoes/airfryer')->assertOk()->assertInertia(fn ($page) => $page->where('promocao.titulo', 'Depois'));
    }
}
