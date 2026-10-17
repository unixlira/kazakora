<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ShopeeRelampagoItem;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Pedido 2026-10-17: às 22h a lista da Relâmpago vai para a Relâmpago da
 * Shopee de amanhã (00h a 24h). Ex.: no dia 17, agenda a do dia 18.
 */
class AgendarRelampagoShopeeTest extends TestCase
{
    use RefreshDatabase;

    private const HORARIO = 512375971328207;

    private const RELAMPAGO = 515649898234622;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-17 22:00', 'America/Sao_Paulo'));

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123',
            'access_token' => 'fake',
            'refresh_token' => 'fake',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);
    }

    private function item(array $dados = []): ShopeeRelampagoItem
    {
        return ShopeeRelampagoItem::create([
            'shopee_item_id' => 58218948968,
            'nome' => 'Kit Macho e Tarracha',
            'preco' => 59.90,
            'unidades' => 40,
            'limite_comprador' => 1,
            ...$dados,
        ]);
    }

    /** Horário de 18/10 00h a 19/10 00h (timestamps de São Paulo). */
    private function horarios(): array
    {
        $inicio = Carbon::parse('2026-10-18 00:00', 'America/Sao_Paulo')->timestamp;

        return ['response' => [
            ['timeslot_id' => self::HORARIO, 'start_time' => $inicio, 'end_time' => $inicio + 86400],
            ['timeslot_id' => 1, 'start_time' => $inicio + 86400, 'end_time' => $inicio + 2 * 86400],
        ]];
    }

    private function shopee(): MockInterface
    {
        $client = Mockery::mock(ShopeeClient::class);
        $this->app->instance(ShopeeClient::class, $client);

        return $client;
    }

    public function test_cria_a_relampago_de_amanha_inclui_e_confere_na_shopee(): void
    {
        $tarracha = $this->item();
        $lixeira = $this->item(['shopee_item_id' => 58216811452, 'nome' => 'Lixeira 3L', 'preco' => 147.90, 'unidades' => 6]);
        $outroDia = $this->item(['shopee_item_id' => 999, 'dia' => '2026-10-20']);

        $client = $this->shopee();
        $client->shouldReceive('get')->andReturnUsing(function (string $path, array $q) {
            return match ($path) {
                '/api/v2/shop_flash_sale/get_time_slot_id' => $this->horarios(),
                '/api/v2/shop_flash_sale/get_shop_flash_sale_list' => ['response' => ['flash_sale_list' => [], 'total_count' => 0]],
                '/api/v2/shop_flash_sale/get_shop_flash_sale_items' => ['response' => ['item_info' => [
                    ['item_id' => 58218948968, 'status' => 1, 'input_promotion_price' => 59.9, 'campaign_stock' => 40, 'reject_reason' => ''],
                    ['item_id' => 58216811452, 'status' => 1, 'input_promotion_price' => 147.9, 'campaign_stock' => 6, 'reject_reason' => 'Not meet the Promotion Price criteria'],
                ]]],
            };
        });
        $client->shouldReceive('post')->once()
            ->with('/api/v2/shop_flash_sale/create_shop_flash_sale', ['timeslot_id' => self::HORARIO])
            ->andReturn(['response' => ['flash_sale_id' => self::RELAMPAGO]]);
        $client->shouldReceive('post')->once()
            ->with('/api/v2/shop_flash_sale/add_shop_flash_sale_items', Mockery::on(fn ($corpo) => $corpo['flash_sale_id'] === self::RELAMPAGO
                && $corpo['items'] === [
                    ['item_id' => 58218948968, 'purchase_limit' => 1, 'item_input_promo_price' => 59.9, 'item_stock' => 40],
                    ['item_id' => 58216811452, 'purchase_limit' => 1, 'item_input_promo_price' => 147.9, 'item_stock' => 6],
                ]))
            ->andReturn(['response' => ['failed_items' => []]]);

        $this->artisan('shopee:relampago-agendar')->assertFailed();

        $this->assertSame(ShopeeRelampagoItem::AGENDADO, $tarracha->fresh()->ultimo_resultado);
        $this->assertSame('2026-10-18', $tarracha->fresh()->ultimo_dia->toDateString());
        $this->assertSame(self::RELAMPAGO, $tarracha->fresh()->ultimo_flash_sale_id);
        $this->assertSame(ShopeeRelampagoItem::RECUSADO, $lixeira->fresh()->ultimo_resultado);
        $this->assertSame('Not meet the Promotion Price criteria', $lixeira->fresh()->ultima_mensagem);
        $this->assertNull($outroDia->fresh()->ultimo_resultado);
    }

    public function test_usa_a_relampago_que_ja_existe_e_nao_mexe_no_item_que_ja_esta_nela(): void
    {
        $tarracha = $this->item();
        $mascaras = $this->item(['shopee_item_id' => 58270571852, 'preco' => null, 'desconto_pct' => 10, 'unidades' => 30]);

        $incluido = false;
        $client = $this->shopee();
        $client->shouldReceive('get')->andReturnUsing(function (string $path) use (&$incluido) {
            $itens = [['item_id' => 58218948968, 'status' => 1, 'input_promotion_price' => 63.9, 'campaign_stock' => 20, 'reject_reason' => '']];
            if ($incluido) {
                $itens[] = ['item_id' => 58270571852, 'status' => 1, 'input_promotion_price' => 35.05, 'campaign_stock' => 30, 'reject_reason' => ''];
            }

            return match ($path) {
                '/api/v2/shop_flash_sale/get_time_slot_id' => $this->horarios(),
                '/api/v2/shop_flash_sale/get_shop_flash_sale_list' => ['response' => ['flash_sale_list' => [
                    ['timeslot_id' => self::HORARIO, 'flash_sale_id' => self::RELAMPAGO, 'status' => 1],
                ]]],
                '/api/v2/shop_flash_sale/get_shop_flash_sale_items' => ['response' => ['item_info' => $itens]],
                '/api/v2/product/get_item_base_info' => ['response' => ['item_list' => [
                    ['item_id' => 58270571852, 'price_info' => [['current_price' => 38.94]]],
                ]]],
            };
        });
        $client->shouldNotReceive('post')->with('/api/v2/shop_flash_sale/create_shop_flash_sale', Mockery::any());
        $client->shouldReceive('post')->once()
            ->with('/api/v2/shop_flash_sale/add_shop_flash_sale_items', [
                'flash_sale_id' => self::RELAMPAGO,
                'items' => [['item_id' => 58270571852, 'purchase_limit' => 1, 'item_input_promo_price' => 35.05, 'item_stock' => 30]],
            ])
            ->andReturnUsing(function () use (&$incluido) {
                $incluido = true;

                return ['response' => ['failed_items' => []]];
            });

        $this->artisan('shopee:relampago-agendar')->assertSuccessful();

        $this->assertSame(ShopeeRelampagoItem::AGENDADO, $tarracha->fresh()->ultimo_resultado);
        $this->assertSame('R$ 63,90, 20 un.', $tarracha->fresh()->ultima_mensagem);
        $this->assertSame(ShopeeRelampagoItem::AGENDADO, $mascaras->fresh()->ultimo_resultado);
    }

    public function test_variacoes_vao_juntas_no_mesmo_item(): void
    {
        $this->item(['shopee_item_id' => 58220705221, 'shopee_model_id' => 74, 'preco' => 250, 'unidades' => 5]);
        $this->item(['shopee_item_id' => 58220705221, 'shopee_model_id' => 75, 'preco' => 500, 'unidades' => 3]);

        $client = $this->shopee();
        $client->shouldReceive('get')->andReturnUsing(fn (string $path) => match ($path) {
            '/api/v2/shop_flash_sale/get_time_slot_id' => $this->horarios(),
            '/api/v2/shop_flash_sale/get_shop_flash_sale_list' => ['response' => ['flash_sale_list' => []]],
        });
        $client->shouldNotReceive('post');

        $this->artisan('shopee:relampago-agendar --simular')
            ->expectsOutputToContain('1 anúncio(s) a incluir')
            ->assertSuccessful();
    }

    public function test_sem_item_na_lista_nao_chama_a_shopee(): void
    {
        $this->item(['ativo' => false]);
        $client = $this->shopee();
        $client->shouldNotReceive('get');
        $client->shouldNotReceive('post');

        $this->artisan('shopee:relampago-agendar')->assertSuccessful();
    }

    public function test_hoje_nao_serve(): void
    {
        $this->item();
        $this->shopee()->shouldNotReceive('post');

        $this->artisan('shopee:relampago-agendar --dia=2026-10-17')->assertFailed();
    }

    public function test_agendado_as_22h_e_desligado_sem_a_chave(): void
    {
        $evento = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'shopee:relampago-agendar'));

        $this->assertNotNull($evento);
        $this->assertSame('0 22 * * *', $evento->expression);
        $this->assertFalse($evento->filtersPass($this->app));

        config(['services.shopee.relampago_automatico' => true]);
        $this->assertTrue($evento->filtersPass($this->app));
    }

    public function test_lista_inclui_e_desliga(): void
    {
        $this->artisan('shopee:relampago-itens incluir 58218948968 --preco=59,90 --unidades=40')->assertSuccessful();
        $this->artisan('shopee:relampago-itens incluir 58218948968 --preco=59.90 --desconto=10 --unidades=40')->assertFailed();

        $item = ShopeeRelampagoItem::sole();
        $this->assertSame(59.9, $item->preco);
        $this->assertSame(0, $item->limite_comprador);
        $this->assertNull($item->dia);

        $this->artisan('shopee:relampago-itens desligar '.$item->id)->assertSuccessful();
        $this->assertFalse($item->fresh()->ativo);
    }
}
