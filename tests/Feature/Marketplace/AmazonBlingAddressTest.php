<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Services\Amazon\AmazonClient;
use App\Services\Bling\BlingOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Achado real 2026-09-28: pedidos Amazon entraram com a etiqueta do Bling
 * ainda montada pelo CEP (número vazio, rua da base dos Correios) e a NF-e
 * nunca saiu — "Preenchimento Obrigatório [nro] E05 enderDest".
 */
class AmazonBlingAddressTest extends TestCase
{
    use RefreshDatabase;

    private const PEDIDO_BLING = [
        'id' => 1000000001,
        'contato' => ['id' => 99],
        'transporte' => ['etiqueta' => [
            'endereco' => 'Rua das Acácias', 'numero' => '45', 'complemento' => 'Casa 2',
            'bairro' => 'Jardim Teste', 'municipio' => 'Campinas', 'uf' => 'SP', 'cep' => '13010000',
        ]],
    ];

    private function driver(?array $pedido, ?array $contato = null): AmazonDriver
    {
        $bling = Mockery::mock(BlingOrderService::class);
        $bling->shouldReceive('amazonLojaId')->andReturn(206308488);
        $bling->shouldReceive('lojaIdForChannel')->andReturn(206308488);
        $bling->shouldReceive('findByOrderNumber')->andReturn($pedido);
        $bling->shouldReceive('findContact')->andReturn($contato);

        return new AmazonDriver(Mockery::mock(AmazonClient::class), $bling);
    }

    private function pedidoSemNumero(): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_AMAZON,
            'external_order_id' => '701-0000000-0000001',
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '19999999999',
            'shipping_zip' => '13010000',
            'shipping_street' => 'Rua Principal do CEP',
            'shipping_number' => '',
            'shipping_neighborhood' => 'Jardim Teste',
            'shipping_city' => 'Campinas',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    public function test_label_without_number_is_completed_by_the_contact_address(): void
    {
        $pedido = ['transporte' => ['etiqueta' => [
            'endereco' => 'Rua Principal do CEP', 'numero' => '', 'bairro' => 'Jardim Teste',
            'municipio' => 'Campinas', 'uf' => 'SP', 'cep' => '13010000',
        ]]];
        $contato = ['endereco' => ['geral' => [
            'endereco' => 'Rua das Acácias', 'numero' => '45', 'complemento' => 'Casa 2',
            'bairro' => 'Jardim Teste', 'municipio' => 'Campinas', 'uf' => 'SP', 'cep' => '13010000',
        ]]];

        $endereco = $this->driver(null)->enderecoDeEntregaDoBling($pedido, $contato);

        $this->assertSame('Rua das Acácias', $endereco['endereco']);
        $this->assertSame('45', $endereco['numero']);
        $this->assertSame('Casa 2', $endereco['complemento']);
    }

    public function test_order_without_number_is_refreshed_from_bling_before_the_invoice(): void
    {
        $order = $this->pedidoSemNumero();

        $this->assertTrue($this->driver(self::PEDIDO_BLING)->atualizarEnderecoPeloBling($order));

        $order->refresh();
        $this->assertSame('Rua das Acácias', $order->shipping_street);
        $this->assertSame('45', $order->shipping_number);
        $this->assertSame('Casa 2', $order->shipping_complement);
    }

    public function test_order_with_number_is_left_alone(): void
    {
        $order = $this->pedidoSemNumero();
        $order->update(['shipping_number' => '10']);

        $this->assertFalse($this->driver(self::PEDIDO_BLING)->atualizarEnderecoPeloBling($order));
        $this->assertSame('Rua Principal do CEP', $order->fresh()->shipping_street);
    }

    public function test_nothing_changes_while_bling_still_has_no_number(): void
    {
        $order = $this->pedidoSemNumero();
        $semNumero = self::PEDIDO_BLING;
        $semNumero['transporte']['etiqueta']['numero'] = '';

        $this->assertFalse($this->driver($semNumero)->atualizarEnderecoPeloBling($order));
        $this->assertSame('', (string) $order->fresh()->shipping_number);
    }
}
