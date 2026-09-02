<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\TikTokShopDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-02 (relatado pelo usuário): pedido com 1 Power Bank
 * PRETO e 1 ROSA aparecia no KoraSync como 2 PRETOS, e quem embala manda a
 * cor errada pro cliente.
 *
 * Causa: as 4 cores têm o MESMO nome no catálogo (a cor só existe no SKU) e
 * o TikTok, via Bling, não manda a cor em campo nenhum — `descricao` é
 * idêntica nos dois itens e `produto.id` vem 0. O desempate por histórico
 * de vendas então escolhia sempre a cor mais vendida (Preto, ~90 pedidos).
 *
 * Sem sinal no dado, parar é melhor que chutar: item sem produto trava a
 * nota (conserto de 30s), item com a cor errada vira encomenda errada na
 * casa do cliente.
 */
class TikTokAmbiguousVariationTest extends TestCase
{
    use RefreshDatabase;

    private function powerBank(string $sku, int $stock): Product
    {
        return Product::factory()->create([
            'sku' => $sku,
            // Mesmo nome nas 4 cores — é exatamente assim no catálogo real.
            'name' => 'Carregador Portátil Power Bank 10000mah Para iPhone E Tipo C',
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    public function test_does_not_pick_a_color_when_several_variations_match_the_same_name(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'metadata' => ['tiktok_loja_id' => 206277670],
        ]);

        $this->powerBank('CAR-CAR-SEM-NAO-PRE-0001', 53);
        $this->powerBank('CAR-CAR-SEM-NAO-ROSA-0001', 31);
        $this->powerBank('CAR-CAR-SEM-POW-BRA-0001', 36);

        // Nome que o TikTok manda, sem cor nenhuma.
        Cache::put('bling.tiktok_item_name.1736780989365650766', 'Mini Carregador Portátil Power Bank 10000mAh 2 em 1 para iPhone e Tipo C com Suporte');

        $escolhido = app(TikTokShopDriver::class)->autoImportProduct('1736780989365650766');

        $this->assertNull($escolhido, 'Sem saber a cor, não pode escolher nenhuma — quem decide é uma pessoa, uma vez só.');
    }

    /**
     * O contrário: nome que casa com UM produto só continua vinculando
     * sozinho, como sempre — a mudança é só sobre o empate.
     */
    public function test_still_links_automatically_when_only_one_product_matches(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'metadata' => ['tiktok_loja_id' => 206277670],
        ]);

        $unico = Product::factory()->create([
            'sku' => 'LIM-MAG-VID-0001',
            'name' => 'Limpador Magnético de Vidros para Janela',
            'stock' => 10,
            'is_active' => true,
        ]);

        Cache::put('bling.tiktok_item_name.CODIGO-UNICO', 'Limpador Magnético de Vidros para Janela Dupla Face');

        $this->assertSame($unico->id, app(TikTokShopDriver::class)->autoImportProduct('CODIGO-UNICO')?->id);
    }
}
