<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tela de pagamento nas Visitas (pedido do Lira, 2026-11-12): a visita da
 * tela de pagamento guarda o total e os itens do checkout, na loja e no
 * FerraKora, e a rota dela no olho traz a lista do que estava no carrinho.
 */
class VisitasItensDoCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    private const FK = ['Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => self::NAVEGADOR];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://kazakora.com', 'frete_embutido.ativo' => false]);
    }

    private function produto(array $dados): Product
    {
        return Product::factory()->create(['is_active' => true, 'discount_percentage' => null, 'discount_amount' => null, 'stock' => 10, ...$dados]);
    }

    private function resumoDoFerraKora(Product $produto, int $quantidade = 2): array
    {
        return $this->withHeaders(self::FK)->postJson('/api/ferrakora/checkout/resumo', [
            'itens' => [['variante' => 'kzv-'.$produto->id, 'quantidade' => $quantidade]],
        ])->assertOk()->json();
    }

    public function test_resumo_do_ferrakora_completa_a_visita_da_tela_de_pagamento(): void
    {
        $trena = $this->produto(['sku' => 'FER-TRE', 'name' => 'Trena 5m', 'price' => 40]);
        FerraKoraProduto::create(['product_id' => $trena->id, 'ordem' => 1, 'ativo' => true]);

        $this->withHeaders(self::FK)->post('/api/ferrakora/visita?p=/produto/trena-5m&e=1')->assertNoContent();
        $this->withHeaders(self::FK)->post('/api/ferrakora/visita?p=/seguro-pagamento&e=0')->assertNoContent();
        $resumo = $this->resumoDoFerraKora($trena);

        $pagamento = SiteVisit::query()->where('path', '/seguro-pagamento')->sole();
        $this->assertEquals($resumo['total'] / 100, $pagamento->preco_visto);
        $this->assertEquals($resumo['frete'] / 100, $pagamento->frete_visto);
        $this->assertEquals([['nome' => 'Trena 5m', 'quantidade' => 2, 'valor' => 80.0]], $pagamento->itens_vistos);
        // A visita da página do produto não é mexida pelo resumo.
        $this->assertNull(SiteVisit::query()->where('path', '/produto/trena-5m')->sole()->itens_vistos);
    }

    public function test_aviso_de_visita_que_chega_depois_do_resumo_pega_os_itens_guardados(): void
    {
        $trena = $this->produto(['sku' => 'FER-TRE', 'name' => 'Trena 5m', 'price' => 40]);
        FerraKoraProduto::create(['product_id' => $trena->id, 'ordem' => 1, 'ativo' => true]);

        $resumo = $this->resumoDoFerraKora($trena, 1);
        $this->withHeaders(self::FK)->post('/api/ferrakora/visita?p=/seguro-pagamento&e=0')->assertNoContent();
        $this->withHeaders(self::FK)->post('/api/ferrakora/visita?p=/produtos&e=0')->assertNoContent();

        $pagamento = SiteVisit::query()->where('path', '/seguro-pagamento')->sole();
        $this->assertEquals($resumo['total'] / 100, $pagamento->preco_visto);
        $this->assertSame('Trena 5m', $pagamento->itens_vistos[0]['nome']);
        $this->assertNull(SiteVisit::query()->where('path', '/produtos')->sole()->preco_visto);
    }

    public function test_tela_de_pagamento_da_loja_grava_o_total_e_os_itens_do_carrinho(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $caneca = $this->produto(['name' => 'Caneca Azul', 'price' => 50]);
        $copo = $this->produto(['name' => 'Copo Verde', 'price' => 20]);

        $this->actingAs($cliente)->post('/carrinho', ['product_id' => $caneca->id, 'quantity' => 2]);
        $this->actingAs($cliente)->post('/carrinho', ['product_id' => $copo->id, 'quantity' => 1]);
        $this->actingAs($cliente)->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/finalizacao')->assertOk();

        $visita = SiteVisit::query()->where('path', '/finalizacao')->sole();
        $this->assertSame(120.0, $visita->preco_visto);
        $this->assertSame(0.0, $visita->frete_visto);
        $this->assertEqualsCanonicalizing(
            [['nome' => 'Caneca Azul', 'quantidade' => 2, 'valor' => 100.0], ['nome' => 'Copo Verde', 'quantidade' => 1, 'valor' => 20.0]],
            $visita->itens_vistos,
        );
    }

    public function test_olho_traz_o_valor_e_os_itens_na_rota_da_tela_de_pagamento(): void
    {
        $this->travelTo('2026-11-12 15:00:00');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $itens = [['nome' => 'Parafusadeira 48V', 'quantidade' => 1, 'valor' => 136.56], ['nome' => 'Trena 5m', 'quantidade' => 2, 'valor' => 80.0]];

        $visita = fn (string $hora, string $path, ?float $preco, ?array $itens = null) => DB::table('site_visits')->insert([
            'site' => 'ferrakora', 'visitor_id' => 'v-1', 'path' => $path, 'preco_visto' => $preco, 'frete_visto' => $preco !== null ? 0 : null,
            'itens_vistos' => $itens !== null ? json_encode($itens) : null, 'ip' => '1.1.1.1',
            'consentiu' => false, 'created_at' => '2026-11-12 '.$hora, 'dispositivo' => 'celular', 'origem' => 'meta_ads',
        ]);
        $visita('10:00:00', '/produto/parafusadeira', 136.56);
        $visita('10:01:00', '/seguro-pagamento', 216.56, $itens);

        $this->actingAs($admin)->getJson('/admin/visitas-ferrakora/visitante?de=2026-11-12&ate=2026-11-12&ip=1.1.1.1&todos=1')
            ->assertOk()
            ->assertJsonPath('rotas.lista.0.path', '/produto/parafusadeira')
            ->assertJsonPath('rotas.lista.0.valor', 136.56)
            ->assertJsonPath('rotas.lista.0.itens', [])
            ->assertJsonPath('rotas.lista.1.path', '/seguro-pagamento')
            ->assertJsonPath('rotas.lista.1.valor', 216.56)
            ->assertJsonPath('rotas.lista.1.itens.0.nome', 'Parafusadeira 48V')
            ->assertJsonPath('rotas.lista.1.itens.1.quantidade', 2)
            ->assertJsonPath('visitas.1.itens.1.nome', 'Trena 5m');
    }
}
