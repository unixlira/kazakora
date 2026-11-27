<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Support\PlanilhaZaio;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Planilha da Zaio no painel (27/11/2026): copiada do Google Sheets para
 * comparar com o resultado real. Não muda o lucro; o painel abre em "Tudo",
 * com os mesmos números do KoraSync.
 */
class PlanilhaZaioTest extends TestCase
{
    use RefreshDatabase;

    private const CSV_SHOPEE = <<<'CSV'
"Informações Básicas Id Anúncio","Nome","Preço Custo","Margem desejada","Preço hoje","","Rentabilidade Renda Estimada Shopee","Margem de contribuição: ","Margem de Contribuição %","","Tarifas & impostos Tarifa do Anúncio","Taxa Fixa","Imposto","Ads/Afiliados","Comissão","Dev. Fácil","Shopee Acelera","","Desconto","Cupom","","Análise Zaio Valor de venda"
"58219448684","Caixa Organizadora Bambu","R$ 66,00","30%","R$ 171,35","","R$ 127,36","R$ 21,95","12,81%","","14,00%","R$ 20,00","10,00%","10,00%","2,00%","R$ 0,00","0,00%","","20,00%","1,00%","","R$ 260,61"
"","","","","","","","","","","","","","","","","","","","","",""
CSV;

    private const CSV_ML = <<<'CSV'
"Id Anúncio","Nome","Preço Custo","Margem desejada","Preço hoje","Peso","Tipo do anúncio","","Renda Estimada Mercado Livre","Margem de contribuição: ","Margem de Contribuição %","","Tarifa do Anúncio","Taxa Fixa","Imposto","Ads/Afiliados","Comissão"
"MLB4923941751","Tabua De Descongelar","R$ 19,00","30%","R$ 38,79","De 0,3 a 0,5 kg","Tarifa Clássica - 14%","","R$ 31,84","R$ 3,92","10,10%","","","R$ 6,95","10,00%","10,00%","2,00%"
CSV;

    public function test_le_as_abas_em_formato_brasileiro(): void
    {
        $shopee = app(PlanilhaZaio::class)->linhasDaAba(self::CSV_SHOPEE, 'shopee');
        $this->assertCount(1, $shopee);
        $this->assertSame('58219448684', $shopee[0]['anuncio_id']);
        $this->assertSame(171.35, $shopee[0]['preco']);
        $this->assertSame(12.81, $shopee[0]['margem_pct']);
        $this->assertSame(14.0, $shopee[0]['tarifa_pct']);
        $this->assertSame(2.0, $shopee[0]['comissao_pct']);
        $this->assertSame(260.61, $shopee[0]['preco_sugerido']);

        $ml = app(PlanilhaZaio::class)->linhasDaAba(self::CSV_ML, 'mercado_livre');
        // Tarifa do ML vem no tipo do anúncio.
        $this->assertSame(14.0, $ml[0]['tarifa_pct']);
        $this->assertSame(6.95, $ml[0]['taxa_fixa']);
    }

    public function test_celula_quebrada_fica_vazia_sem_travar(): void
    {
        $this->assertNull(PlanilhaZaio::reais('-R$ 1.737.850.064.376.390.000,00'));
        $this->assertSame(-12.5, PlanilhaZaio::reais('-R$ 12,50'));
        $this->assertSame(1403.45, PlanilhaZaio::reais('R$ 1.403,45'));
    }

    public function test_sincroniza_liga_o_produto_e_o_painel_mostra_zaio_e_real_sem_mudar_o_lucro(): void
    {
        Carbon::setTestNow('2026-11-27 15:00:00');
        config(['loja.simples.aliquota' => 4.5, 'loja.insumos_por_pedido' => 0.0]);
        $produto = Product::factory()->create(['name' => 'Caixa Organizadora Bambu', 'price' => 100, 'cost_price' => 40, 'stock' => 10]);
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => 'shopee',
            'shipping_name' => 'Ana', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 100,
        ]);
        $pedido->items()->create(['product_id' => $produto->id, 'external_item_id' => '58219448684', 'product_name' => 'Caixa', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        config(['loja.zaio.planilha_id' => 'planilha-de-teste']);
        Http::fake(fn ($request) => Http::response(str_contains(urldecode($request->url()), 'Shopee') ? self::CSV_SHOPEE : (str_contains(urldecode($request->url()), 'Mercado') ? self::CSV_ML : '"Id Anúncio","Nome","Preço hoje"')));
        $antes = app(ContributionMargin::class)->periodo(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-28'));

        $this->artisan('zaio:planilha')->assertSuccessful();

        $this->assertSame($produto->id, (int) DB::table('zaio_anuncios')->where('anuncio_id', '58219448684')->value('product_id'));
        $depois = app(ContributionMargin::class)->periodo(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-28'));
        $this->assertSame($antes['lucro'], $depois['lucro'], 'a planilha da Zaio não muda o lucro');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/dashboard-financeiro')->assertOk()->assertInertia(fn ($page) => $page
            ->where('grupo', 'todos')
            ->where('zaio.linhas.0.anuncio', '58219448684')
            ->where('zaio.linhas.0.unidades', 1)
            ->where('zaio.linhas.0.margemZaioPct', fn ($v) => abs($v - 12.81) < 0.001)
            // 100 − 40 de custo − 4,50 de imposto = 55,50 (sem taxa nem ADS no teste).
            ->where('zaio.linhas.0.lucroRealUn', fn ($v) => abs($v - 55.5) < 0.001));
    }
}
