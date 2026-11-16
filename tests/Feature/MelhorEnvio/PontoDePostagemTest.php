<?php

namespace Tests\Feature\MelhorEnvio;

use App\Models\MelhorEnvioToken;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Modules\Marketplace\Support\PontoDePostagemMelhorEnvio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Onde levar o pacote do Melhor Envio (2026-11-09): ponto padrão ou a agência mais próxima. */
class PontoDePostagemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.melhorenvio.api_base_url' => 'https://me.test/api/v2', 'melhorenvio.ponto_padrao' => 41970]);
        MelhorEnvioToken::create(['account_label' => 'KazaKora ME', 'access_token' => 'a', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(20), 'scopes' => []]);
        Company::create(['razao_social' => 'Kazakora', 'cnpj' => '65604590000107', 'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'zip' => '03187-040', 'city' => 'São Paulo', 'state' => 'SP']);
        $agencia = fn ($id, $nome, $lat, $cias) => ['id' => $id, 'name' => $nome, 'address' => ['address' => 'Rua '.$id, 'number' => '1', 'district' => 'Mooca', 'latitude' => $lat, 'longitude' => -46.5826], 'companies' => array_map(fn ($c) => ['id' => $c], $cias)];
        Http::fake([
            'me.test/api/v2/me/shipment/services' => Http::response([
                ['id' => 33, 'name' => 'Standard', 'company' => ['id' => 15, 'name' => 'JeT']],
                ['id' => 35, 'name' => 'Standard', 'company' => ['id' => 8, 'name' => 'Total Express']],
            ]),
            'me.test/api/v2/me/shipment/agencies*' => Http::response([
                $agencia(41970, '[PEGAKI] - DISTRIBUE - Ponto Parceiro', -23.5620, [1, 2, 14, 15]),
                $agencia(500, 'Total longe', -23.6000, [8]),
                $agencia(501, 'Total perto', -23.5650, [8]),
            ]),
        ]);
    }

    private function envio(int $servico): MelhorEnvioEnvio
    {
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01000-000', 'shipping_street' => 'R', 'shipping_number' => '1',
            'shipping_neighborhood' => 'C', 'shipping_city' => 'SP', 'shipping_state' => 'SP', 'subtotal' => 1, 'total' => 1]);

        return MelhorEnvioEnvio::create(['order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'servico_id' => $servico]);
    }

    public function test_transportadora_aceita_no_ponto_padrao_vai_para_ele(): void
    {
        $envio = $this->envio(33);
        app(PontoDePostagemMelhorEnvio::class)->gravar($envio);

        $this->assertSame('[PEGAKI] - DISTRIBUE', $envio->fresh()->postagem['nome']);
        $this->assertTrue($envio->fresh()->postagem['padrao']);
        $this->assertSame('JeT', $envio->fresh()->postagem['transportadora']);
    }

    public function test_transportadora_que_o_ponto_nao_aceita_vai_para_a_agencia_mais_proxima_dela(): void
    {
        $envio = $this->envio(35);
        app(PontoDePostagemMelhorEnvio::class)->gravar($envio);

        $this->assertSame('Total perto', $envio->fresh()->postagem['nome']);
        $this->assertFalse($envio->fresh()->postagem['padrao']);
    }
}
