<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Pedido do dono, 18/11/2026: o products.name virou título de vitrine
 * ("Acorde Sem Dor no Pescoço + Guia PDF ..."). No KoraSync e na NF-e o
 * item do pedido de marketplace sai com o título do anúncio do canal; sem
 * título, cai no nome interno do produto (e só então no name).
 */
class NomeDoItemPeloCanalTest extends TestCase
{
    use RefreshDatabase;

    private const VITRINE = 'Acorde Sem Dor no Pescoço + Guia PDF Coluna Blindada';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function produtoMapeado(string $canal, array $attrs = []): Product
    {
        $produto = Product::factory()->create(['stock' => 10, 'name' => self::VITRINE] + $attrs);

        ProductChannelListing::create([
            'product_id' => $produto->id,
            'channel' => $canal,
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => 'ITEM-1',
        ]);

        return $produto;
    }

    /** @return array<string, mixed> */
    private function pedido(?string $tituloDoCanal, string $externalId = 'PED-1'): array
    {
        return [
            'external_order_id' => $externalId,
            'status' => Order::STATUS_PAID,
            'channel_status' => 'paid',
            'buyer_name' => 'Cliente Teste',
            'buyer_document' => null,
            'buyer_phone' => null,
            'buyer_email' => null,
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_complement' => null,
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'shipping_cost' => 10,
            'total' => 110,
            'items' => [
                ['external_id' => 'ITEM-1', 'external_model_id' => null, 'external_name' => $tituloDoCanal, 'unit_price' => 100, 'quantity' => 1],
            ],
        ];
    }

    private function importar(string $canal, array $dados): Order
    {
        return app(OrderImportService::class)->importNormalized($canal, $dados, dispatchShippingConfirmation: false);
    }

    public function test_mercado_livre_grava_o_titulo_do_anuncio_e_nao_o_name(): void
    {
        $this->produtoMapeado(MarketplaceAccount::CHANNEL_MERCADO_LIVRE);

        $pedido = $this->importar(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $this->pedido('Travesseiro Cervical Ortopédico Viscoelástico'));

        $this->assertSame('Travesseiro Cervical Ortopédico Viscoelástico', $pedido->items->first()->product_name);
    }

    public function test_shopee_grava_o_titulo_do_anuncio_com_a_variacao(): void
    {
        $this->produtoMapeado(MarketplaceAccount::CHANNEL_SHOPEE);

        $pedido = $this->importar(MarketplaceAccount::CHANNEL_SHOPEE, $this->pedido('Power Bank 5000mAh - Rosa'));

        $this->assertSame('Power Bank 5000mAh - Rosa', $pedido->items->first()->product_name);
    }

    public function test_sem_titulo_do_canal_cai_no_nome_interno_do_produto(): void
    {
        $this->produtoMapeado(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, ['nome_interno' => 'Travesseiro Cervical']);

        $pedido = $this->importar(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $this->pedido(null));

        $this->assertSame('Travesseiro Cervical', $pedido->items->first()->product_name);
    }

    public function test_sem_titulo_do_canal_nem_nome_interno_cai_no_name(): void
    {
        $this->produtoMapeado(MarketplaceAccount::CHANNEL_SHOPEE);

        $pedido = $this->importar(MarketplaceAccount::CHANNEL_SHOPEE, $this->pedido('  '));

        $this->assertSame(self::VITRINE, $pedido->items->first()->product_name);
    }

    public function test_comando_corrige_pedido_de_canal_relendo_o_titulo_no_canal(): void
    {
        $this->produtoMapeado(MarketplaceAccount::CHANNEL_MERCADO_LIVRE);
        $pedido = $this->importar(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $this->pedido(null, 'ML-9'));
        $this->assertSame(self::VITRINE, $pedido->items->first()->product_name);

        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('importOrder')->with('ML-9')->andReturn($this->pedido('Título do anúncio no ML', 'ML-9'));
        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with(MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->andReturn($driver);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $this->artisan('pedidos:nome-do-item-pelo-canal', ['--desde' => now()->subHour()->toDateTimeString(), '--aplicar' => true])->assertSuccessful();

        $this->assertSame('Título do anúncio no ML', $pedido->items()->first()->product_name);
    }

    public function test_comando_corrige_pedido_da_loja_com_o_nome_interno_e_sem_aplicar_nao_grava(): void
    {
        $produto = Product::factory()->create(['name' => self::VITRINE, 'nome_interno' => 'Travesseiro Cervical']);
        $pedido = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '1', 'shipping_zip' => '01001000', 'shipping_street' => 'R',
            'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 39.00, 'shipping_cost' => 0, 'total' => 39.00,
        ]);
        $pedido->items()->create(['product_id' => $produto->id, 'product_name' => self::VITRINE, 'product_price' => 39.00, 'quantity' => 1, 'subtotal' => 39.00, 'item_type' => OrderItem::TYPE_PRODUCT]);

        $desde = now()->subHour()->toDateTimeString();

        $this->artisan('pedidos:nome-do-item-pelo-canal', ['--desde' => $desde])->assertSuccessful();
        $this->assertSame(self::VITRINE, $pedido->items()->first()->product_name);

        $this->artisan('pedidos:nome-do-item-pelo-canal', ['--desde' => $desde, '--aplicar' => true])->assertSuccessful();
        $this->assertSame('Travesseiro Cervical', $pedido->items()->first()->product_name);
    }

    public function test_nome_para_pedido_usa_nome_interno_e_cai_no_name(): void
    {
        $this->assertSame('Interno', (new Product(['name' => self::VITRINE, 'nome_interno' => 'Interno']))->nomeParaPedido());
        $this->assertSame(self::VITRINE, (new Product(['name' => self::VITRINE, 'nome_interno' => '']))->nomeParaPedido());
    }
}
