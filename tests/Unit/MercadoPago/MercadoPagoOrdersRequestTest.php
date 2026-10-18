<?php

namespace Tests\Unit\MercadoPago;

use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Formato dos POST da API de Orders (2026-10-18, pedido #3219). Conferido
 * na API real: sem X-Idempotency-Key ela responde 400 "empty_required_header"
 * e com corpo [] responde 400 "expected object, but got array".
 */
class MercadoPagoOrdersRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.modo_teste' => false,
            'services.mercadopago.access_token' => 'TOKEN-TESTE',
            'services.mercadopago.api_base_url' => 'https://api.mercadopago.com',
        ]);

        Http::fake(['*' => Http::response(['id' => 'ORD01X', 'status' => 'refunded'])]);
    }

    public static function acoes(): array
    {
        return [
            'estorno' => ['refundOrder', 'refund', 'estorno-ORD01X'],
            'cancelamento' => ['cancelOrder', 'cancel', 'cancelamento-ORD01X'],
            'captura' => ['captureOrder', 'capture', 'captura-ORD01X'],
        ];
    }

    #[DataProvider('acoes')]
    public function test_post_vazio_vai_como_objeto_e_com_chave_fixa_pela_order(string $metodo, string $acao, string $chave): void
    {
        app(MercadoPagoPaymentService::class)->{$metodo}('ORD01X');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), "v1/orders/ORD01X/{$acao}")
            && $request->body() === '{}'
            && $request->header('X-Idempotency-Key') === [$chave]);
    }

    public function test_estorno_usa_a_chave_do_chamador_e_o_valor_parcial(): void
    {
        app(MercadoPagoPaymentService::class)->refundOrder('ORD01X', 10.5, 'estorno-pagamento-7');

        Http::assertSent(fn (Request $request) => $request->data() === ['amount' => 10.5]
            && $request->header('X-Idempotency-Key') === ['estorno-pagamento-7']);
    }
}
