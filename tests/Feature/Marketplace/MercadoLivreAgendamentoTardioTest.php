<?php

namespace Tests\Feature\Marketplace;

use App\Models\MercadoLivreToken;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pedido #3600 (2026-10-28): importado sem agendamento, o ML agendou a
 * venda depois e o pedido ficou em "Aguardando separação" no KoraSync.
 */
class MercadoLivreAgendamentoTardioTest extends TestCase
{
    use RefreshDatabase;

    private array $resposta = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-28 15:30:00', 'America/Sao_Paulo'));

        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 123456789,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token',
            'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);

        Http::fake(fn ($request) => str_ends_with($request->url(), '/shipments/48369972249')
            ? Http::response($this->resposta)
            : Http::response([], 404));
    }

    private function envio(?string $agendado = null): ChannelShipment
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => '2000019215295428',
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '06010170',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Osasco',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);

        return ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'external_shipment_id' => '48369972249',
            'shipping_method' => 'xd_drop_off',
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'scheduled_for' => $agendado,
        ]);
    }

    private function mlResponde(string $status, string $substatus, ?string $buffering): void
    {
        $this->resposta = [
            'id' => 48369972249,
            'status' => $status,
            'substatus' => $substatus,
            'shipping_option' => $buffering ? ['buffering' => ['date' => $buffering]] : [],
            'status_history' => [],
        ];
    }

    public function test_venda_agendada_depois_da_importacao_ganha_a_data(): void
    {
        $envio = $this->envio();
        $this->mlResponde('pending', 'buffered', '2026-10-30T00:00:00.000Z');

        app(ShipmentService::class)->syncOrderStatusFromShipment($envio);

        $this->assertSame('2026-10-30', $envio->refresh()->scheduled_for?->toDateString());
    }

    public function test_envio_sem_agendamento_continua_sem_data(): void
    {
        $envio = $this->envio();
        $this->mlResponde('ready_to_ship', 'ready_to_print', null);

        app(ShipmentService::class)->syncOrderStatusFromShipment($envio);

        $this->assertNull($envio->refresh()->scheduled_for);
    }

    public function test_data_ja_gravada_nao_e_trocada(): void
    {
        $envio = $this->envio('2026-11-04 00:00:00');
        $this->mlResponde('pending', 'buffered', '2026-10-30T00:00:00.000Z');

        app(ShipmentService::class)->syncOrderStatusFromShipment($envio);

        $this->assertSame('2026-11-04', $envio->refresh()->scheduled_for?->toDateString());
    }
}
