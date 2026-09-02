<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Canal cuja NF-e é emitida pelo Bling (hoje só faria sentido pro TikTok
 * Shop, ver services.bling.invoice_issuer_channels): emitir aqui também
 * geraria DUAS notas pra mesma venda.
 *
 * O motivo de existir essa chave, achado testando a API do Bling ao vivo
 * em 2026-09-02: o TikTok só libera etiqueta depois de receber o XML, e o
 * Bling só repassa nota pra loja quando ELE gerou a nota a partir do
 * pedido — `POST /nfe` cria nota solta (ele ignora referência a pedido no
 * corpo, confirmado) e nota solta não é repassada.
 */
class BlingIssuedChannelSkipsOwnInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $origin): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => $origin,
            'external_order_id' => 'EXT-'.uniqid(),
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    public function test_channel_delegated_to_bling_does_not_issue_an_invoice_here(): void
    {
        config(['services.bling.invoice_issuer_channels' => [Order::ORIGIN_TIKTOK_SHOP]]);

        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        (new GenerateInvoiceJob($order->id))->handle(
            app(\App\Modules\Fiscal\Services\InvoiceService::class),
            app(\App\Modules\Checkout\Support\OrderFulfillmentTimeline::class),
            app(\App\Modules\Marketplace\Support\OrderImportService::class),
        );

        $this->assertNull($order->fresh()->invoice, 'Não pode existir nota nossa num canal emitido pelo Bling.');
        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $order->id,
            'step' => \App\Modules\Checkout\Models\OrderFulfillmentEvent::STEP_INVOICE_ISSUED,
        ]);
    }

    /**
     * Com a chave vazia (padrão de hoje), nada muda pra canal nenhum — a
     * emissão continua sendo nossa. Aqui o pedido não tem itens, então a
     * emissão falha; o que importa é que ela foi TENTADA (o job não saiu
     * antes da hora), e é isso que a ausência do evento de delegação prova.
     */
    public function test_without_the_flag_the_channel_still_goes_through_our_own_emission(): void
    {
        config(['services.bling.invoice_issuer_channels' => []]);

        $order = $this->makeOrder(Order::ORIGIN_TIKTOK_SHOP);

        try {
            (new GenerateInvoiceJob($order->id))->handle(
                app(\App\Modules\Fiscal\Services\InvoiceService::class),
                app(\App\Modules\Checkout\Support\OrderFulfillmentTimeline::class),
                app(\App\Modules\Marketplace\Support\OrderImportService::class),
            );
        } catch (\Throwable) {
            // Pedido sem item não emite mesmo — irrelevante pro que se testa aqui.
        }

        $this->assertDatabaseMissing('order_fulfillment_events', [
            'order_id' => $order->id,
            'step' => \App\Modules\Checkout\Models\OrderFulfillmentEvent::STEP_INVOICE_ISSUED,
            'message' => 'Emissão delegada ao Bling para este canal — nenhuma nota emitida aqui.',
        ]);
    }
}
