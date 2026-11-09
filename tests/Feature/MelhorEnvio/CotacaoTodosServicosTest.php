<?php

namespace Tests\Feature\MelhorEnvio;

use App\Models\MelhorEnvioToken;
use App\Services\MelhorEnvio\CotacaoMelhorEnvio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ACHADO REAL 2026-11-09 (#3978): sem "services" o Melhor Envio cota um
 * serviço só (Loggi Ponto), que recusava a caixa de 115 cm da árvore de Natal.
 */
class CotacaoTodosServicosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cotacao_pede_todos_os_servicos_e_aceita_objeto_unico(): void
    {
        config(['services.melhorenvio.api_base_url' => 'https://me.test/api/v2']);
        MelhorEnvioToken::create([
            'account_label' => 'KazaKora ME', 'access_token' => 'acesso', 'refresh_token' => 'r1',
            'token_expires_at' => now()->addDays(20), 'scopes' => [],
        ]);
        Http::fake(['me.test/*' => Http::response(['id' => 33, 'name' => 'Standard', 'price' => '39.40', 'delivery_time' => 8, 'company' => ['id' => 9, 'name' => 'JeT']])]);

        $opcoes = app(CotacaoMelhorEnvio::class)->cotarUm([
            'origem' => '03187040', 'cep' => '45605482', 'peso_g' => 5000,
            'altura' => 115, 'largura' => 15, 'comprimento' => 16, 'valor' => 100,
        ]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'me/shipment/calculate')
            && $r['services'] === config('melhorenvio.servicos')
            && str_contains($r['services'], '33'));
        $this->assertSame(33, $opcoes[0]['servico_id']);
        $this->assertSame(39.4, $opcoes[0]['preco']);
    }
}
