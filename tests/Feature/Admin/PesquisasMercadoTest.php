<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Models\PesquisaMercado;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu Pesquisas (pedido do Lira 2026-10-12): pesquisas de mercado por
 * plataforma, com os produtos observados, e a aba Curva ABC por canal.
 */
class PesquisasMercadoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'plataforma' => 'mercado_livre',
            'titulo' => 'Organizadores de cozinha',
            'data_pesquisa' => '2026-10-12',
            'status' => 'rascunho',
            'resumo' => "Faixa de preço entre R$ 39 e R$ 59.\nKit com 3 vende mais.",
            'itens' => [
                [
                    'produto' => 'Organizador concorrente A',
                    'link' => 'https://produto.mercadolivre.com.br/MLB-123',
                    'preco' => 49.9,
                    'vendas' => '+5 mil vendidos',
                    'avaliacoes' => '4,8 (1.200)',
                    'vendedor' => 'Loja X',
                    'observacoes' => 'Frete grátis',
                    'nota' => 8.5,
                ],
                ['produto' => 'Organizador concorrente B', 'preco' => 39.9],
            ],
        ], $extra);
    }

    public function test_admin_cria_pesquisa_com_itens_e_ela_aparece_na_aba_certa(): void
    {
        $admin = $this->admin();
        $nosso = Product::factory()->create(['name' => 'Nosso organizador']);
        $dados = $this->dados();
        $dados['itens'][1]['product_id'] = $nosso->id;

        $this->actingAs($admin)->post('/admin/pesquisas', $dados)->assertRedirect();

        $pesquisa = PesquisaMercado::query()->with('itens')->firstOrFail();
        $this->assertSame($admin->id, $pesquisa->user_id);
        $this->assertSame('mercado_livre', $pesquisa->plataforma);
        $this->assertCount(2, $pesquisa->itens);
        $this->assertSame('Organizador concorrente A', $pesquisa->itens[0]->produto);
        $this->assertSame(49.9, $pesquisa->itens[0]->preco);
        $this->assertSame($nosso->id, $pesquisa->itens[1]->product_id);

        $this->actingAs($admin)->get('/admin/pesquisas?aba=mercado_livre')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Pesquisas/Index', false)
                ->has('pesquisas.data', 1)
                ->where('pesquisas.data.0.titulo', 'Organizadores de cozinha')
                ->where('pesquisas.data.0.itens_count', 2)
                ->where('contagem.mercado_livre', 1)
                ->where('contagem.shopee', 0));

        $this->actingAs($admin)->get('/admin/pesquisas?aba=shopee')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('pesquisas.data', 0));

        $this->actingAs($admin)->get("/admin/pesquisas/{$pesquisa->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Pesquisas/Show', false)
                ->where('pesquisa.plataforma_label', 'Mercado Livre')
                ->where('pesquisa.autor.name', $admin->name)
                ->where('pesquisa.itens.1.product.name', 'Nosso organizador'));
    }

    public function test_outra_plataforma_exige_nome_e_cai_em_outras(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/pesquisas', $this->dados(['plataforma' => 'outra']))
            ->assertSessionHasErrors('plataforma_nome');
        $this->assertSame(0, PesquisaMercado::count());

        $this->actingAs($admin)->post('/admin/pesquisas', $this->dados(['plataforma' => 'outra', 'plataforma_nome' => 'Shein']))
            ->assertRedirect();
        $this->actingAs($admin)->post('/admin/pesquisas', $this->dados(['plataforma' => 'tiktok_shop', 'titulo' => 'Garrafas']))
            ->assertRedirect();

        $this->actingAs($admin)->get('/admin/pesquisas?aba=outras')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pesquisas.data', 2)
                ->where('contagem.outras', 2)
                ->where('pesquisas.data', fn ($lista) => collect($lista)->pluck('plataforma_label')->sort()->values()->all() === ['Shein', 'TikTok Shop']));
    }

    public function test_busca_e_filtro_de_status(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/pesquisas', $this->dados());
        $this->actingAs($admin)->post('/admin/pesquisas', $this->dados([
            'titulo' => 'Luminárias', 'status' => 'concluida', 'itens' => [['produto' => 'Abajur', 'vendedor' => 'Casa Luz']],
        ]));

        $this->actingAs($admin)->get('/admin/pesquisas?aba=mercado_livre&busca=Casa%20Luz')
            ->assertInertia(fn ($page) => $page->has('pesquisas.data', 1)->where('pesquisas.data.0.titulo', 'Luminárias'));

        $this->actingAs($admin)->get('/admin/pesquisas?aba=mercado_livre&status=rascunho')
            ->assertInertia(fn ($page) => $page->has('pesquisas.data', 1)->where('pesquisas.data.0.titulo', 'Organizadores de cozinha'));
    }

    public function test_editar_troca_dados_e_itens(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/pesquisas', $this->dados());
        $pesquisa = PesquisaMercado::query()->firstOrFail();

        $this->actingAs($admin)->get("/admin/pesquisas/{$pesquisa->id}/editar")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Pesquisas/Form', false)->has('pesquisa.itens', 2));

        $this->actingAs($admin)->put("/admin/pesquisas/{$pesquisa->id}", $this->dados([
            'plataforma' => 'shopee',
            'status' => 'concluida',
            'titulo' => 'Organizadores (revisada)',
            'itens' => [['produto' => 'Só este', 'preco' => 59.9]],
        ]))->assertRedirect("/admin/pesquisas/{$pesquisa->id}");

        $pesquisa->refresh()->load('itens');
        $this->assertSame('shopee', $pesquisa->plataforma);
        $this->assertSame('concluida', $pesquisa->status);
        $this->assertSame('Organizadores (revisada)', $pesquisa->titulo);
        $this->assertCount(1, $pesquisa->itens);
        $this->assertSame('Só este', $pesquisa->itens[0]->produto);
    }

    public function test_validacao_de_link_e_nota(): void
    {
        $this->actingAs($this->admin())->post('/admin/pesquisas', $this->dados([
            'itens' => [['produto' => 'X', 'link' => 'nao-e-link', 'nota' => 11]],
        ]))->assertSessionHasErrors(['itens.0.link', 'itens.0.nota']);

        $this->assertSame(0, PesquisaMercado::count());
    }

    public function test_permissoes(): void
    {
        $assinante = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $pesquisa = PesquisaMercado::create([
            'plataforma' => 'shopee', 'titulo' => 'Teste', 'data_pesquisa' => '2026-10-12', 'status' => 'rascunho',
        ]);

        // Cliente da loja não entra no admin.
        $this->actingAs($cliente)->get('/admin/pesquisas')->assertForbidden();

        // Assinante só consulta.
        $this->actingAs($assinante)->get('/admin/pesquisas')->assertOk();
        $this->actingAs($assinante)->get("/admin/pesquisas/{$pesquisa->id}")->assertOk();
        $this->actingAs($assinante)->get('/admin/pesquisas/nova')->assertForbidden();
        $this->actingAs($assinante)->post('/admin/pesquisas', $this->dados())->assertForbidden();
        $this->actingAs($assinante)->put("/admin/pesquisas/{$pesquisa->id}", $this->dados())->assertForbidden();

        // Gerente cria e edita, mas não remove.
        $this->actingAs($gerente)->post('/admin/pesquisas', $this->dados())->assertRedirect();
        $this->actingAs($gerente)->put("/admin/pesquisas/{$pesquisa->id}", $this->dados())->assertRedirect();
        $this->actingAs($gerente)->delete("/admin/pesquisas/{$pesquisa->id}")->assertForbidden();

        // Admin remove: vai para a lixeira (soft delete).
        $this->actingAs($this->admin())->delete("/admin/pesquisas/{$pesquisa->id}")->assertRedirect();
        $this->assertSoftDeleted('pesquisas_mercado', ['id' => $pesquisa->id]);
    }

    public function test_curva_abc_do_canal_com_vendas_efetivas_e_variacoes_somadas(): void
    {
        $pai = Product::factory()->create(['name' => 'Garrafa', 'cost_price' => 10]);
        $azul = Product::factory()->create(['name' => 'Garrafa Azul', 'parent_product_id' => $pai->id, 'cost_price' => 10]);
        $outro = Product::factory()->create(['name' => 'Copo', 'cost_price' => 5]);
        $pequeno = Product::factory()->create(['name' => 'Tampa', 'cost_price' => 1]);

        $venda = function (Product $produto, int $qtd, float $preco, array $pedido = []) {
            $order = Order::create(array_merge([
                'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_SHOPEE,
                'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
                'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
                'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
                'subtotal' => $qtd * $preco, 'total' => $qtd * $preco,
            ], $pedido));
            $order->items()->create([
                'product_id' => $produto->id, 'product_name' => $produto->name, 'product_price' => $preco,
                'quantity' => $qtd, 'subtotal' => $qtd * $preco,
            ]);

            return $order;
        };

        // Garrafa: 400 (pai) + 400 (variação azul) = 800; Copo 150; Tampa 50.
        $venda($pai, 4, 100);
        $comTaxa = $venda($azul, 4, 100);
        \App\Modules\Marketplace\Models\OrderChannelFee::create(['order_id' => $comTaxa->id, 'channel' => 'shopee', 'gross_amount' => 400, 'fee_amount' => 80, 'source' => 'api', 'computed_at' => now()]);
        $venda($outro, 3, 50);
        $venda($pequeno, 5, 10);
        // Fora: cancelado, outro canal e venda estornada.
        $venda($pequeno, 99, 100, ['status' => Order::STATUS_CANCELLED]);
        $venda($pequeno, 99, 100, ['origin' => Order::ORIGIN_MERCADO_LIVRE]);
        $venda($pequeno, 99, 100)->forceFill(['estornado_em' => now()])->save();

        $this->actingAs($this->admin())->get('/admin/pesquisas/curva-abc?canal=shopee&dias=30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Pesquisas/CurvaAbc', false)
                ->where('curva.total', 1000)
                ->where('curva.produtos.0.nome', 'Garrafa')
                ->where('curva.produtos.0.classe', 'A')
                ->where('curva.produtos.0.quantidade', 8)
                ->where('curva.produtos.0.custo', 80)
                ->where('curva.produtos.0.taxas', 80)
                ->where('curva.produtos.0.margem', 640)
                ->where('curva.produtos.1.nome', 'Copo')
                ->where('curva.produtos.1.classe', 'B')
                ->where('curva.produtos.2.nome', 'Tampa')
                ->where('curva.produtos.2.classe', 'C')
                ->where('curva.resumo', ['a' => 1, 'b' => 1, 'c' => 1])
                ->where('curva.canal_oficial.receita', 1000)
                ->where('curva.canal_oficial.taxas', 80));
    }

    public function test_curva_abc_exige_permissao_do_financeiro(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        \App\Models\RolePermission::query()
            ->where('role', User::ROLE_MANAGER)
            ->where('permission', 'financeiro.view')
            ->update(['granted' => false]);

        $this->actingAs($gerente)->get('/admin/pesquisas')->assertOk();
        $this->actingAs($gerente)->get('/admin/pesquisas/curva-abc')->assertForbidden();
    }
}
