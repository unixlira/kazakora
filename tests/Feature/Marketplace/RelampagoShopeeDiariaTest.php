<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Mail\RelampagoShopeeResumo;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ShopeeRelampagoExecucao;
use App\Modules\Marketplace\Models\ShopeeRelampagoItem;
use App\Services\Shopee\ShopeeClient;
use App\Services\Shopee\ShopeeRelampagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Oferta Relâmpago diária da Shopee (pedido 2026-10-17): preço com o
 * desconto necessário, lucro mínimo e criação da Relâmpago de amanhã.
 */
class RelampagoShopeeDiariaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 4.5, 'shopee_relampago.ativa' => true, 'shopee_relampago.dias_a_frente' => 1,
            'loja.email_relatorios' => 'lira@exemplo.com']);
        Carbon::setTestNow(Carbon::parse('2026-10-17 09:15', 'America/Sao_Paulo'));
    }

    private function item(array $dados): ShopeeRelampagoItem
    {
        return ShopeeRelampagoItem::create($dados + ['nome' => 'Produto', 'estoque' => 10, 'limite_por_comprador' => 1]);
    }

    public function test_lucro_pela_faixa_de_taxa_da_shopee(): void
    {
        // 134,90: 14% + 20 = 38,886; Simples 6,0705; custo 73,40 → 16,54 (conta do Cauê).
        $this->assertSame(16.54, ShopeeRelampagoService::lucroUnidade(134.90, 73.40));
        // 37,94: 20% + 4,50 = 12,088; Simples 1,7073; custo 12 → 12,14.
        $this->assertSame(12.14, ShopeeRelampagoService::lucroUnidade(37.94, 12));
        $this->assertNull(ShopeeRelampagoService::lucroUnidade(50, null));
        // Regra do Lira 17/10: R$ 10 até R$ 200, R$ 30 acima.
        $this->assertSame(10.0, ShopeeRelampagoService::lucroMinimo(200));
        $this->assertSame(30.0, ShopeeRelampagoService::lucroMinimo(200.01));
    }

    public function test_preco_fixo_do_lira_vale_e_baixa_para_1_por_cento_quando_precisa(): void
    {
        $fixo = $this->item(['item_id' => 1, 'modo' => 'preco', 'valor' => 134.90, 'custo' => 73.40]);
        $p = ShopeeRelampagoService::precificar($fixo, 148.90, null);
        $this->assertSame(134.90, $p['preco']);
        $this->assertFalse($p['ajustado']);
        $this->assertNull($p['motivo']);

        // Anúncio baixou para 135: 134,90 fica a menos de 1% → 133,65.
        $p = ShopeeRelampagoService::precificar($fixo, 135.00, null);
        $this->assertSame(133.65, $p['preco']);
        $this->assertTrue($p['ajustado']);
        $this->assertNull($p['motivo']);

        // Relâmpago no ar: o preço lido do anúncio não serve de base.
        $p = ShopeeRelampagoService::precificar($fixo, 134.90, 134.90);
        $this->assertSame(134.90, $p['preco']);
        $this->assertFalse($p['ajustado']);
    }

    public function test_desconto_em_reais_respeita_1_por_cento_e_lucro_minimo(): void
    {
        $semCusto = $this->item(['item_id' => 2, 'modo' => 'desconto_reais', 'valor' => 1]);
        $this->assertSame('sem custo no cadastro para conferir o lucro', ShopeeRelampagoService::precificar($semCusto, 49.90, null)['motivo']);

        $produto = Product::factory()->create(['cost_price' => 20]);
        $ring = $this->item(['item_id' => 3, 'modo' => 'desconto_reais', 'valor' => 1, 'product_id' => $produto->id]);
        $p = ShopeeRelampagoService::precificar($ring->load('product'), 49.90, null);
        $this->assertSame(48.90, $p['preco']);
        $this->assertSame(12.42, $p['lucro']);
        $this->assertNull($p['motivo']);

        // −R$ 1,00 num item de 148,90 é menos de 1%: baixa para 147,41.
        $lixeira = $this->item(['item_id' => 4, 'modo' => 'desconto_reais', 'valor' => 1, 'custo' => 73.40]);
        $this->assertSame(147.41, ShopeeRelampagoService::precificar($lixeira, 148.90, null)['preco']);

        // Abaixo do lucro mínimo fica de fora.
        $apertado = $this->item(['item_id' => 5, 'modo' => 'desconto_pct', 'valor' => 30, 'custo' => 15]);
        $p = ShopeeRelampagoService::precificar($apertado, 49.90, null);
        $this->assertSame(34.93, $p['preco']);
        $this->assertStringContainsString('abaixo do mínimo', $p['motivo']);

        // Preço fixo com prejuízo também fica de fora.
        $prejuizo = $this->item(['item_id' => 6, 'modo' => 'preco', 'valor' => 595.90, 'custo' => 526]);
        $this->assertStringContainsString('prejuízo', ShopeeRelampagoService::precificar($prejuizo, 596.90, null)['motivo']);
    }

    private function shopee(array &$posts, array $naRelampago = [], array $falhas = []): void
    {
        $amanha = Carbon::parse('2026-10-18', 'America/Sao_Paulo')->startOfDay()->timestamp;
        MarketplaceAccount::create(['channel' => 'shopee', 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour()]);

        $this->mock(ShopeeClient::class, function ($m) use (&$posts, $amanha, $naRelampago, $falhas) {
            $m->shouldReceive('get')->andReturnUsing(function (string $path, array $q = []) use ($amanha, $naRelampago) {
                return match ($path) {
                    '/api/v2/shop_flash_sale/get_time_slot_id' => ['response' => [['timeslot_id' => 900, 'start_time' => $amanha, 'end_time' => $amanha + 86400]]],
                    '/api/v2/shop_flash_sale/get_shop_flash_sale_list' => ['response' => ['flash_sale_list' => $q['type'] === 1 && $naRelampago
                        ? [['timeslot_id' => 900, 'flash_sale_id' => 777, 'start_time' => $amanha, 'end_time' => $amanha + 86400]] : []]],
                    '/api/v2/shop_flash_sale/get_shop_flash_sale_items' => ['response' => ['item_info' => $naRelampago]],
                    '/api/v2/shop_flash_sale/get_shop_flash_sale' => ['response' => ['enabled_item_count' => 2]],
                    '/api/v2/product/get_item_base_info' => ['response' => ['item_list' => [
                        ['item_id' => 11, 'item_status' => 'NORMAL', 'price_info' => [['current_price' => 148.90]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 6]]],
                        ['item_id' => 22, 'item_status' => 'NORMAL', 'price_info' => [['current_price' => 49.90]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 100]]],
                        ['item_id' => 33, 'item_status' => 'UNLIST', 'price_info' => [['current_price' => 99]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 5]]],
                    ]]],
                };
            });
            $m->shouldReceive('post')->andReturnUsing(function (string $path, array $dados) use (&$posts, $falhas) {
                $posts[] = [$path, $dados];

                return match ($path) {
                    '/api/v2/shop_flash_sale/create_shop_flash_sale' => ['response' => ['flash_sale_id' => 777]],
                    '/api/v2/shop_flash_sale/add_shop_flash_sale_items' => ['response' => ['failed_items' => $falhas]],
                };
            });
        });
    }

    public function test_cria_a_relampago_de_amanha_com_a_lista_e_avisa(): void
    {
        Mail::fake();
        $this->item(['item_id' => 11, 'nome' => 'Lixeira 3L', 'modo' => 'preco', 'valor' => 134.90, 'estoque' => 75, 'custo' => 73.40]);
        // Sem unidades definidas: metade do estoque do anúncio (100).
        $this->item(['item_id' => 22, 'nome' => 'Ring Light', 'modo' => 'preco', 'valor' => 48.90, 'estoque' => null, 'limite_por_comprador' => 0, 'custo' => 20]);
        $this->item(['item_id' => 33, 'nome' => 'Árvore', 'modo' => 'preco', 'valor' => 90, 'custo' => 10]);
        $this->item(['item_id' => 44, 'nome' => 'Desligado', 'modo' => 'preco', 'valor' => 10, 'ativo' => false]);
        $posts = [];
        $this->shopee($posts, [], [['item_id' => 22, 'err_msg' => 'item failed', 'unqualified_conditions' => [['unqualified_msg' => 'Your Promo Price is higher than the lowest price in the last 7 days']]]]);

        $this->artisan('shopee:relampago-diaria')->assertSuccessful();

        $this->assertSame('/api/v2/shop_flash_sale/create_shop_flash_sale', $posts[0][0]);
        $this->assertSame(['timeslot_id' => 900], $posts[0][1]);
        $this->assertSame(['flash_sale_id' => 777, 'items' => [
            ['item_id' => 11, 'purchase_limit' => 1, 'item_input_promo_price' => 134.90, 'item_stock' => 6],
            ['item_id' => 22, 'purchase_limit' => 0, 'item_input_promo_price' => 48.90, 'item_stock' => 50],
        ]], $posts[1][1]);

        $e = ShopeeRelampagoExecucao::sole();
        $this->assertSame('2026-10-18', $e->dia->toDateString());
        $this->assertSame('parcial', $e->status);
        $this->assertSame(1, $e->adicionados);
        $this->assertSame(1, $e->recusados);
        $this->assertStringContainsString('lowest price', $e->detalhe['recusados'][0]['motivo']);
        $this->assertSame('anúncio UNLIST na Shopee', $e->detalhe['pulados'][0]['motivo']);
        Mail::assertSent(RelampagoShopeeResumo::class, fn ($m) => $m->hasTo('lira@exemplo.com'));
    }

    public function test_segunda_rodada_nao_repete_quem_ja_esta_e_fica_quieta(): void
    {
        Mail::fake();
        $this->item(['item_id' => 11, 'nome' => 'Lixeira 3L', 'modo' => 'preco', 'valor' => 134.90, 'custo' => 73.40]);
        $posts = [];
        $this->shopee($posts, [['item_id' => 11, 'status' => 1, 'input_promotion_price' => 134.90]]);

        $this->artisan('shopee:relampago-diaria')->assertSuccessful();

        $this->assertSame([], $posts, 'nada é criado nem enviado');
        Mail::assertNothingSent();
    }

    public function test_desligada_ou_seco_nao_cria_nada(): void
    {
        Mail::fake();
        $this->item(['item_id' => 11, 'nome' => 'Lixeira 3L', 'modo' => 'preco', 'valor' => 134.90, 'custo' => 73.40]);
        $posts = [];
        $this->shopee($posts);

        config(['shopee_relampago.ativa' => false]);
        $this->artisan('shopee:relampago-diaria')->expectsOutputToContain('desligada')->assertSuccessful();

        $this->artisan('shopee:relampago-diaria --seco')->expectsOutputToContain('1 entrariam')->assertSuccessful();

        $this->assertSame([], $posts);
        $this->assertSame(0, ShopeeRelampagoExecucao::count());
        Mail::assertNothingSent();
    }
}
