<?php

namespace Tests\Feature\Api;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * KoraFlex: a lista do dia (com o corte das 12:00) e a bipagem do QR da
 * etiqueta do Flex.
 */
class KoraFlexControllerTest extends TestCase
{
    use RefreshDatabase;

    /** O QR real de uma etiqueta do Flex (envio 47981054232, 2026-09-10). */
    private const QR_REAL = '{"id":"47981054232","sender_id":3283064948,"hash_code":"IQj2uT93EJTG7OCltp55McJj6NY35H/WECjLJKiGVz8=","security_digit":"0"}';

    private function headers(): array
    {
        return ['Authorization' => 'Bearer test-koraflex-token'];
    }

    private function makeFlexOrder(string $envio, ?Carbon $vendidaEm = null, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'VENDA-'.$envio,
            'shipping_name' => 'Cliente Flex',
            'shipping_phone' => 'Não informado',
            'shipping_zip' => '06010170',
            'shipping_street' => 'Rua Teste',
            'shipping_number' => 'S/N',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Osasco',
            'shipping_state' => 'SP',
            'subtotal' => 0,
            'shipping_cost' => 0,
            'total' => 0,
        ], $attributes));

        if ($vendidaEm) {
            $order->forceFill(['created_at' => $vendidaEm])->save();
        }

        ChannelShipment::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'external_shipment_id' => $envio,
            'tracking_code' => $envio,
            'shipping_method' => ChannelShipment::METHOD_FLEX,
            'status' => ChannelShipment::STATUS_LABEL_READY,
        ]);

        return $order->fresh();
    }

    public function test_it_refuses_a_request_without_the_koraflex_token(): void
    {
        $this->getJson('/api/koraflex/dia')->assertStatus(401);
    }

    /**
     * A REGRA DO CORTE, pedida pelo usuário: "se a venda saiu no dia após
     * horário de corte, não deve aparecer, isso seria regra para aparecer
     * no envio do dia seguinte".
     */
    public function test_the_day_list_stops_at_the_cutoff_and_pushes_later_sales_to_tomorrow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 15:00:00'));

        $ontemDeTarde = $this->makeFlexOrder('1000', Carbon::parse('2026-09-09 15:30:00'));
        $hojeDeManha = $this->makeFlexOrder('1001', Carbon::parse('2026-09-10 09:00:00'));
        $depoisDoCorte = $this->makeFlexOrder('1002', Carbon::parse('2026-09-10 12:01:00'));

        $resposta = $this->getJson('/api/koraflex/dia', $this->headers())->assertOk();

        $pedidos = collect($resposta->json('entregas'))->pluck('pedido');

        $this->assertTrue($pedidos->contains($ontemDeTarde->id), 'venda de ontem depois do corte sai hoje');
        $this->assertTrue($pedidos->contains($hojeDeManha->id), 'venda de hoje antes do corte sai hoje');
        $this->assertFalse($pedidos->contains($depoisDoCorte->id), 'venda depois do corte fica pro dia seguinte');

        $this->assertSame(2, $resposta->json('total'));
        $this->assertSame('12:00', $resposta->json('corte'));

        // E amanhã ela aparece.
        Carbon::setTestNow(Carbon::parse('2026-09-11 08:00:00'));

        $amanha = collect($this->getJson('/api/koraflex/dia', $this->headers())->json('entregas'))->pluck('pedido');

        $this->assertTrue($amanha->contains($depoisDoCorte->id));
    }

    /** Caixa esquecida de ontem não pode sumir da tela — vai pra "atrasados". */
    public function test_an_unscanned_order_from_a_previous_day_shows_up_as_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 15:00:00'));

        $velha = $this->makeFlexOrder('900', Carbon::parse('2026-09-05 10:00:00'));

        $resposta = $this->getJson('/api/koraflex/dia', $this->headers())->assertOk();

        $this->assertSame(0, $resposta->json('total'));
        $this->assertSame($velha->id, $resposta->json('atrasados.0.pedido'));
    }

    public function test_scanning_the_real_flex_qr_marks_the_order_ready_for_pickup(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));

        $order = $this->makeFlexOrder('47981054232', Carbon::parse('2026-09-10 08:00:00'));

        $resposta = $this->postJson('/api/koraflex/bipar', ['qr' => self::QR_REAL, 'dispositivo' => 'iPhone da loja'], $this->headers())
            ->assertOk();

        $this->assertTrue($resposta->json('ok'));
        $this->assertSame('pronto', $resposta->json('motivo'));
        $this->assertSame('pronto', $resposta->json('venda.estado'));

        $order->refresh();

        $this->assertNotNull($order->ready_for_pickup_at);
        // Bipar É a separação concluída: a caixa está fechada na mão de quem bipou.
        $this->assertNotNull($order->packed_at);

        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $order->id,
            'step' => 'ready_for_pickup',
            'status' => 'success',
        ]);
    }

    /** Bipar duas vezes não é erro nem conta duas — só informa a hora da primeira. */
    public function test_scanning_twice_is_idempotent_and_reports_the_first_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));

        $order = $this->makeFlexOrder('47981054232', Carbon::parse('2026-09-10 08:00:00'));

        $this->postJson('/api/koraflex/bipar', ['qr' => self::QR_REAL], $this->headers())->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-10 10:30:00'));

        $segunda = $this->postJson('/api/koraflex/bipar', ['qr' => self::QR_REAL], $this->headers())->assertOk();

        $this->assertTrue($segunda->json('ok'));
        $this->assertSame('ja_estava_pronto', $segunda->json('motivo'));
        $this->assertSame('10/09/2026 10:00', $segunda->json('ja_estava_pronto_em'));

        $this->assertSame('10/09 10:00', $order->refresh()->ready_for_pickup_at->format('d/m H:i'));
    }

    /** Uma caixa, uma etiqueta, dois pedidos: os dois têm que ser carimbados. */
    public function test_scanning_a_pack_marks_every_order_that_shares_the_shipment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));

        $primeiro = $this->makeFlexOrder('47981054232', Carbon::parse('2026-09-10 08:00:00'));
        $segundo = $this->makeFlexOrder('47981054232', Carbon::parse('2026-09-10 08:05:00'), ['external_order_id' => 'VENDA-IRMA']);

        $resposta = $this->postJson('/api/koraflex/bipar', ['qr' => self::QR_REAL], $this->headers())->assertOk();

        $this->assertNotNull($primeiro->refresh()->ready_for_pickup_at);
        $this->assertNotNull($segundo->refresh()->ready_for_pickup_at);
        $this->assertEqualsCanonicalizing([$primeiro->id, $segundo->id], $resposta->json('no_pack'));
    }

    public function test_it_refuses_a_cancelled_sale_loudly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));

        $order = $this->makeFlexOrder('47981054232', Carbon::parse('2026-09-10 08:00:00'));
        $order->forceFill(['status' => Order::STATUS_CANCELLED])->save();

        $resposta = $this->postJson('/api/koraflex/bipar', ['qr' => self::QR_REAL], $this->headers())->assertOk();

        $this->assertFalse($resposta->json('ok'));
        $this->assertSame('cancelada', $resposta->json('motivo'));
        $this->assertNull($order->refresh()->ready_for_pickup_at);
    }

    public function test_it_refuses_a_label_that_is_not_flex(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));

        $order = $this->makeFlexOrder('47981054232', Carbon::parse('2026-09-10 08:00:00'));
        $order->channelShipment->forceFill(['shipping_method' => ChannelShipment::METHOD_DROP_OFF])->save();

        $resposta = $this->postJson('/api/koraflex/bipar', ['qr' => self::QR_REAL], $this->headers())->assertOk();

        $this->assertFalse($resposta->json('ok'));
        $this->assertSame('nao_e_flex', $resposta->json('motivo'));
    }

    public function test_it_reports_an_unknown_label_instead_of_failing_silently(): void
    {
        $resposta = $this->postJson('/api/koraflex/bipar', ['qr' => '{"id":"99999999999"}'], $this->headers())->assertOk();

        $this->assertFalse($resposta->json('ok'));
        $this->assertSame('nao_encontrada', $resposta->json('motivo'));
    }

    /** O leitor pode entregar o número puro (ou alguém digita) — tem que funcionar igual. */
    public function test_it_accepts_the_bare_shipment_number_too(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));

        $order = $this->makeFlexOrder('47981054232', Carbon::parse('2026-09-10 08:00:00'));

        $this->postJson('/api/koraflex/bipar', ['qr' => '47981054232'], $this->headers())->assertOk();

        $this->assertNotNull($order->refresh()->ready_for_pickup_at);
    }

    public function test_undo_puts_the_order_back_to_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));

        $order = $this->makeFlexOrder('47981054232', Carbon::parse('2026-09-10 08:00:00'));

        $this->postJson('/api/koraflex/bipar', ['qr' => self::QR_REAL], $this->headers())->assertOk();

        $resposta = $this->postJson('/api/koraflex/desfazer', ['pedido' => $order->id], $this->headers())->assertOk();

        $this->assertTrue($resposta->json('ok'));
        $this->assertNull($order->refresh()->ready_for_pickup_at);
        // A separação continua verdadeira — a caixa foi separada mesmo.
        $this->assertNotNull($order->packed_at);
    }
}
