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

    public function test_turbo_e_a_mais_barata_ate_um_dia_da_mais_rapida(): void
    {
        $o = fn ($id, $preco, $prazo) => ['servico_id' => $id, 'nome' => "s{$id}", 'preco' => $preco, 'prazo' => $prazo];

        // SEDEX 3 dias R$ 97, J&T 4 dias R$ 79, Azul 4 dias R$ 185, PAC 9 dias R$ 47.
        $this->assertSame(33, CotacaoMelhorEnvio::turbo([$o(2, 97.0, 3), $o(33, 79.0, 4), $o(15, 185.0, 4), $o(1, 47.0, 9)])['servico_id']);
        // Sem SEDEX: Azul 4 dias R$ 185 x J&T 5 dias R$ 79 -> J&T (dentro de 1 dia).
        $this->assertSame(33, CotacaoMelhorEnvio::turbo([$o(15, 185.0, 4), $o(33, 79.0, 5), $o(1, 47.0, 9)])['servico_id']);
        // Só a rápida dentro da folga: ela.
        $this->assertSame(2, CotacaoMelhorEnvio::turbo([$o(2, 97.0, 3), $o(1, 47.0, 9)])['servico_id']);
    }
}
