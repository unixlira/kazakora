<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Http\Controllers\GoogleMerchantFeedController;
use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * Feed do Google Merchant Center (pedido 2026-11-23): /feeds/google.xml.
 */
class GoogleMerchantFeedTest extends TestCase
{
    use RefreshDatabase;

    private const G = 'http://base.google.com/ns/1.0';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(GoogleMerchantFeedController::CACHE);
        config(['app.url' => 'https://kazakora.com']);
    }

    /** @return array<string, SimpleXMLElement> itens do feed por g:id */
    private function itens(): array
    {
        $resposta = $this->get('/feeds/google.xml')->assertOk();
        $this->assertStringContainsString('application/xml', (string) $resposta->headers->get('Content-Type'));

        $previo = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($resposta->getContent());
        $erros = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        $this->assertNotFalse($xml, 'XML inválido: '.json_encode(array_map(fn ($e) => trim($e->message), $erros)));
        $this->assertSame('rss', $xml->getName());
        $this->assertSame('2.0', (string) $xml['version']);

        $itens = [];
        foreach ($xml->channel->item as $item) {
            $g = $item->children(self::G);
            $itens[(string) $g->id] = $g;
        }

        return $itens;
    }

    private function produto(array $dados = []): Product
    {
        $produto = Product::factory()->create([
            'is_active' => true,
            'so_ferrakora' => false,
            'parent_product_id' => null,
            'cost_price' => 0,
            'discount_percentage' => null,
            'discount_amount' => null,
            'stock' => 5,
            ...$dados,
        ]);
        $produto->images()->create(['path' => "products/{$produto->id}/foto.jpg", 'position' => 0, 'is_primary' => true]);

        return $produto;
    }

    public function test_produto_ativo_entra_com_preco_frete_gratis_e_link_certo(): void
    {
        $produto = $this->produto([
            'slug' => 'panela-eletrica',
            'name' => 'Panela Elétrica & Cia',
            'description' => '<p>Cozinha <strong>rápido</strong></p><ul><li>3 litros &amp; tampa</li></ul>',
            'price' => 123.45,
            'brand' => null,
        ]);
        $produto->images()->create(['path' => "products/{$produto->id}/foto-2.jpg", 'position' => 1, 'is_primary' => false]);

        $item = $this->itens()[(string) $produto->id] ?? null;

        $this->assertNotNull($item, 'produto ativo ficou fora do feed');
        $this->assertSame('Panela Elétrica & Cia', (string) $item->title);
        $this->assertSame('Cozinha rápido 3 litros & tampa', (string) $item->description);
        $this->assertSame('https://kazakora.com/produtos/panela-eletrica', (string) $item->link);
        $this->assertSame("https://kazakora.com/storage/products/{$produto->id}/foto.jpg", (string) $item->image_link);
        $this->assertSame("https://kazakora.com/storage/products/{$produto->id}/foto-2.jpg", (string) $item->additional_image_link);
        $this->assertSame('in_stock', (string) $item->availability);
        $this->assertSame('123.45 BRL', (string) $item->price);
        $this->assertCount(0, $item->sale_price);
        $this->assertSame('KazaKora', (string) $item->brand);
        $this->assertSame('no', (string) $item->identifier_exists);
        $this->assertSame('new', (string) $item->condition);
        $this->assertSame('BR', (string) $item->shipping->children(self::G)->country);
        $this->assertSame('0.00 BRL', (string) $item->shipping->children(self::G)->price);
    }

    public function test_inativo_sem_preco_so_ferrakora_e_variacao_ficam_de_fora(): void
    {
        $ativo = $this->produto();
        $inativo = $this->produto(['is_active' => false]);
        $semPreco = $this->produto(['price' => 0]);
        $soFerraKora = $this->produto(['so_ferrakora' => true]);
        $variacao = $this->produto(['parent_product_id' => $ativo->id]);

        $ids = array_keys($this->itens());

        $this->assertContains((string) $ativo->id, $ids);
        foreach ([$inativo, $semPreco, $soFerraKora, $variacao] as $fora) {
            $this->assertNotContains((string) $fora->id, $ids);
        }
    }

    public function test_desconto_vai_em_sale_price_esgotado_e_gtin_valido(): void
    {
        $produto = $this->produto(['price' => 100, 'discount_percentage' => 10, 'stock' => 0, 'brand' => 'Mondial']);
        ProductFiscalData::create(['product_id' => $produto->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => '84629920', 'gtin' => '7891234567895']);
        $gtinErrado = $this->produto();
        ProductFiscalData::create(['product_id' => $gtinErrado->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => '84629920', 'gtin' => '7891234567890']);

        $itens = $this->itens();
        $item = $itens[(string) $produto->id];

        $this->assertSame('100.00 BRL', (string) $item->price);
        $this->assertSame('90.00 BRL', (string) $item->sale_price);
        $this->assertSame('out_of_stock', (string) $item->availability);
        $this->assertSame('Mondial', (string) $item->brand);
        $this->assertSame('7891234567895', (string) $item->gtin);
        $this->assertCount(0, $item->identifier_exists);

        $this->assertCount(0, $itens[(string) $gtinErrado->id]->gtin);
        $this->assertSame('no', (string) $itens[(string) $gtinErrado->id]->identifier_exists);
    }

    public function test_frete_embutido_e_o_de_quem_nao_tem_regiao(): void
    {
        config(['frete_embutido.ativo' => true]);
        $produto = $this->produto(['price' => 50]);
        $semRegiao = 50 + (float) config('frete_embutido.fallback_pac');

        // Quem abre o feed primeiro (aqui, de SP pela Cloudflare) não muda o preço.
        $resposta = $this->withHeaders(['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP'])->get('/feeds/google.xml')->assertOk();
        $item = simplexml_load_string($resposta->getContent())->channel->item[0]->children(self::G);

        $this->assertSame((string) $produto->id, (string) $item->id);
        $this->assertSame(number_format($semRegiao, 2, '.', '').' BRL', (string) $item->price);
        $this->assertSame('0.00 BRL', (string) $item->shipping->children(self::G)->price);
    }
}
