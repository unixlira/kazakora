<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** bling:corrigir-notas (2026-10-11): CFOP 6108 → 6102 e NCM do cadastro. */
class CorrigirNotasBlingTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.bling.api_base_url' => 'https://api.bling.test/Api/v3', 'services.bling.auto_send_nfe' => true]);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(),
            'metadata' => ['tiktok_loja_id' => 206277670],
        ]);
        Company::create(['razao_social' => 'Kazakora', 'cnpj' => '65604590000107', 'regime_tributario' => 'simples_nacional',
            'zip' => '01000-000', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP']);
        $produto = Product::factory()->create(['sku' => 'NIV-1']);
        ProductFiscalData::create(['product_id' => $produto->id, 'ncm' => '90153000', 'cfop' => '5102', 'cfop_outros_estados' => '6102', 'origem' => 2]);
        ProductChannelListing::create(['product_id' => $produto->id, 'channel' => Order::ORIGIN_TIKTOK_SHOP, 'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED, 'external_id' => 'NIV-1']);
        $this->order = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_TIKTOK_SHOP,
            'external_order_id' => 'TK9', 'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '59000000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'Natal',
            'shipping_state' => 'RN', 'subtotal' => 97, 'total' => 97,
        ]);
        Invoice::create(['order_id' => $this->order->id, 'serie' => 3, 'numero' => 468, 'status' => Invoice::STATUS_PENDING,
            'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO]);
    }

    private function fake(): void
    {
        Http::fake([
            '*/pedidos/vendas/9' => Http::response(['data' => ['id' => 9, 'numeroLoja' => 'TK9', 'notaFiscal' => ['id' => 468]]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [['id' => 9, 'numeroLoja' => 'TK9']]]),
            '*/nfe/468/enviar' => Http::response(['data' => []]),
            '*/nfe/468' => function (Request $r) {
                if ($r->method() === 'PUT') {
                    return Http::response(['data' => ['id' => 468]]);
                }

                return Http::response(['data' => ['id' => 468, 'serie' => '3', 'numero' => '000468', 'situacao' => 1, 'chaveAcesso' => '',
                    'itens' => [['codigo' => 'NIV-1', 'cfop' => '6108', 'classificacaoFiscal' => '', 'origem' => 0]]]]);
            },
        ]);
    }

    public function test_simulacao_nao_escreve_no_bling(): void
    {
        $this->fake();

        $this->artisan('bling:corrigir-notas')->expectsOutputToContain('cfop: 6108 → 6102')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT' || str_contains($r->url(), '/enviar'));
    }

    public function test_aplicar_corrige_cfop_ncm_origem_sem_enviar(): void
    {
        $this->fake();

        $this->artisan('bling:corrigir-notas', ['--aplicar' => true])->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r['itens'][0]['cfop'] === '6102' && $r['itens'][0]['classificacaoFiscal'] === '9015.30.00' && $r['itens'][0]['origem'] === 2
            && $r['numero'] === '000468' && $r['serie'] === '3' && ! isset($r['situacao'])
            && $r['naturezaOperacao']['id'] === 15110696182);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/enviar'));
    }

    public function test_uf_da_nota_manda_no_cfop(): void
    {
        // Pedido diz RN, mas a nota vai para SP (mesma UF da empresa): 5102.
        Http::fake([
            '*/pedidos/vendas/9' => Http::response(['data' => ['id' => 9, 'numeroLoja' => 'TK9', 'notaFiscal' => ['id' => 468]]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [['id' => 9, 'numeroLoja' => 'TK9']]]),
            '*/nfe/468' => fn (Request $r) => $r->method() === 'PUT'
                ? Http::response(['data' => ['id' => 468]])
                : Http::response(['data' => ['id' => 468, 'serie' => '3', 'numero' => '000468', 'situacao' => 4,
                    'contato' => ['endereco' => ['uf' => 'SP']],
                    'itens' => [['codigo' => 'NIV-1', 'cfop' => '6108', 'classificacaoFiscal' => '9015.30.00', 'origem' => 2]]]]),
        ]);

        $this->artisan('bling:corrigir-notas', ['--aplicar' => true])->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['itens'][0]['cfop'] === '5102');
    }

    public function test_pula_serie_2_e_pedido_zerado(): void
    {
        $this->fake();
        $this->order->invoice->update(['serie' => 2]);
        $this->artisan('bling:corrigir-notas', [$this->order->id, '--aplicar' => true, '--enviar' => true])->assertSuccessful();

        $this->order->invoice->update(['serie' => 3]);
        $this->order->update(['total' => 0]);
        $this->artisan('bling:corrigir-notas', [$this->order->id, '--aplicar' => true, '--enviar' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_nao_mexe_em_pedido_com_nota_autorizada(): void
    {
        $this->fake();
        $this->order->invoice->update(['status' => Invoice::STATUS_AUTHORIZED, 'serie' => 2]);

        $this->artisan('bling:corrigir-notas', [$this->order->id, '--aplicar' => true, '--enviar' => true])->assertSuccessful();

        Http::assertNothingSent();
    }
}
