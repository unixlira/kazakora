<?php

namespace Tests\Feature\Logistica;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\Rastreio;
use App\Modules\Marketplace\Models\RastreioEvento;
use App\Modules\Marketplace\Support\RegistroDeRastreio;
use App\Services\Correios\CorreiosRastroService;
use App\Services\Correios\CorreiosTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Rastreio dos Correios com histórico (2026-10-19): consulta em lote no SRO
 * Rastro, eventos gravados sem duplicar, postado vira "enviado", entregue
 * vira "concluído" com um único WhatsApp (só com a chave ligada), objeto
 * entregue ou devolvido sai da consulta, outros canais só guardam histórico.
 *
 * Os objetos abaixo têm o formato real devolvido pela API em 19/10/2026
 * (AE018212819BR e AP629988851BR), sem dado pessoal.
 */
class RastreioCorreiosTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array> objeto da API por código */
    private array $objetos = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 20:00:00', 'America/Sao_Paulo'));
        config([
            'services.correios.rastreio_whatsapp' => true,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.templates.pedido_entregue' => 'pedido_entregue',
            'services.whatsapp.templates.pedido_enviado' => 'pedido_enviado',
        ]);
        $this->mock(CorreiosTokenService::class, fn ($m) => $m->shouldReceive('tokenForPrecoPrazo')->andReturn('tok'));

        Http::fake([
            'api.correios.com.br/*' => function (HttpRequest $r) {
                preg_match_all('/codigosObjetos=([A-Z0-9]+)/', $r->url(), $m);

                return Http::response(['versao' => '3.8.2', 'quantidade' => count($m[1]), 'tipoResultado' => 'Todos os Eventos',
                    'objetos' => array_map(fn ($c) => $this->objetos[$c] ?? ['codObjeto' => $c, 'mensagem' => 'SRO-020: Objeto não encontrado na base de dados dos Correios.'], $m[1])]);
            },
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function entregue(string $codigo = 'AE018212819BR', string $cidade = 'SAO PAULO'): array
    {
        $u = fn (string $tipo, ?string $c, ?string $destino = null) => array_filter([
            'unidade' => ['tipo' => $tipo, 'endereco' => ['cidade' => $c]],
            'unidadeDestino' => $destino ? ['endereco' => ['cidade' => $destino]] : null,
        ]);

        return ['codObjeto' => $codigo, 'dtPrevista' => '2026-10-20T23:59:59', 'tipoPostal' => ['sigla' => 'AE', 'categoria' => 'ENCOMENDA PAC'], 'eventos' => [
            ['codigo' => 'BDE', 'tipo' => '01', 'dtHrCriado' => '2026-10-15T19:26:47', 'descricao' => 'Objeto entregue ao destinatário', 'detalhe' => 'Queremos te ouvir!', 'recebedor' => ['nome' => 'NAO GRAVA'], 'latitude' => '-23.5'] + $u('Unidade de Distribuição', $cidade),
            ['codigo' => 'OEC', 'tipo' => '01', 'dtHrCriado' => '2026-10-15T09:29:49', 'descricao' => 'Objeto saiu para entrega ao destinatário'] + $u('Unidade de Distribuição', $cidade),
            ['codigo' => 'RO', 'tipo' => '01', 'dtHrCriado' => '2026-10-13T17:13:51', 'descricao' => 'Objeto em transferência - por favor aguarde'] + $u('Agência dos Correios', 'SAO PAULO', 'SAO PAULO'),
            ['codigo' => 'PO', 'tipo' => '01', 'dtHrCriado' => '2026-10-13T14:08:48', 'descricao' => 'Objeto postado'] + $u('Agência dos Correios', 'SAO PAULO'),
            ['codigo' => 'FC', 'tipo' => '82', 'dtHrCriado' => '2026-10-13T00:05:36', 'descricao' => 'Etiqueta emitida', 'detalhe' => 'Aguardando postagem pelo remetente'] + $u('INTERFACE DO SISTEMA', null),
        ]];
    }

    public static function postado(string $codigo = 'AP629988851BR'): array
    {
        return ['codObjeto' => $codigo, 'dtPrevista' => '2026-10-28T23:59:59', 'eventos' => [
            ['codigo' => 'RO', 'tipo' => '01', 'dtHrCriado' => '2026-10-15T17:15:24', 'descricao' => 'Objeto em transferência - por favor aguarde', 'unidade' => ['endereco' => ['cidade' => 'SAO PAULO']], 'unidadeDestino' => ['endereco' => ['cidade' => 'CAJAMAR']]],
            ['codigo' => 'PO', 'tipo' => '01', 'dtHrCriado' => '2026-10-15T16:31:56', 'descricao' => 'Objeto postado', 'unidade' => ['endereco' => ['cidade' => 'SAO PAULO']]],
            ['codigo' => 'FC', 'tipo' => '82', 'dtHrCriado' => '2026-10-15T11:41:20', 'descricao' => 'Etiqueta emitida', 'detalhe' => 'Aguardando postagem pelo remetente'],
        ]];
    }

    private function pedido(string $codigo, array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(11) 98888-7777', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));

        CorreiosPrePostagem::query()->forceCreate([
            'order_id' => $order->id, 'codigo_objeto' => $codigo, 'status' => CorreiosPrePostagem::STATUS_GERADA,
            'customer_name' => 'Maria', 'zip' => '03187040', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Mooca',
            'city' => 'São Paulo', 'state' => 'SP', 'service_code' => '03298', 'weight_grams' => 500, 'content_items' => '[]',
        ]);

        return $order;
    }

    private function chamadasCorreios()
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.correios.com.br'))->values();
    }

    private function whatsapps()
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))->values();
    }

    public function test_interpreta_resposta_real_do_sro(): void
    {
        $r = CorreiosRastroService::interpretar(self::entregue());
        $this->assertTrue($r['postado']);
        $this->assertSame('2026-10-13 14:08:48', $r['postado_em']->format('Y-m-d H:i:s'));
        $this->assertTrue($r['entregue']);
        $this->assertSame('2026-10-15 19:26:47', $r['entregue_em']->format('Y-m-d H:i:s'));
        $this->assertSame('SAO PAULO', $r['entregue_cidade']);
        $this->assertSame('entregue', RegistroDeRastreio::situacao(self::entregue()));

        $p = CorreiosRastroService::interpretar(self::postado());
        $this->assertTrue($p['postado']);
        $this->assertFalse($p['entregue']);
        $this->assertSame('2026-10-28', $p['previsao']->toDateString());
        $this->assertSame('em_transito', RegistroDeRastreio::situacao(self::postado()));

        // Só a etiqueta (FC): ainda não foi postado.
        $etiqueta = ['eventos' => [self::postado()['eventos'][2]]];
        $this->assertFalse(CorreiosRastroService::interpretar($etiqueta)['postado']);
        $this->assertSame('aguardando_postagem', RegistroDeRastreio::situacao($etiqueta));
        $this->assertSame('erro', RegistroDeRastreio::situacao(['mensagem' => 'SRO-020: Objeto não encontrado']));

        $this->assertTrue(CorreiosRastroService::codigoValido('AE018212819BR'));
        $this->assertFalse(CorreiosRastroService::codigoValido('MEL123'));
    }

    public function test_consulta_em_lote_numa_chamada_so(): void
    {
        $this->objetos = ['AP629988851BR' => self::postado(), 'AE018212819BR' => self::entregue()];
        $postado = $this->pedido('AP629988851BR');
        $entregue = $this->pedido('AE018212819BR', ['status' => Order::STATUS_SHIPPED]);

        $this->artisan('loja:acompanhar-rastreio', ['--sem-outros-canais' => true])->assertSuccessful();

        $this->assertCount(1, $this->chamadasCorreios());
        $url = urldecode($this->chamadasCorreios()[0][0]->url());
        $this->assertStringContainsString('/srorastro/v1/objetos?', $url);
        $this->assertStringContainsString('codigosObjetos=AP629988851BR&codigosObjetos=AE018212819BR&resultado=T', $url);
        $this->assertSame(Order::STATUS_SHIPPED, $postado->fresh()->status);
        $this->assertSame(Order::STATUS_COMPLETED, $entregue->fresh()->status);
    }

    public function test_postado_vira_enviado_e_grava_o_historico(): void
    {
        $this->objetos = ['AP629988851BR' => self::postado()];
        $order = $this->pedido('AP629988851BR');

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $rastreio = Rastreio::query()->where('codigo', 'AP629988851BR')->firstOrFail();
        $this->assertSame($order->id, $rastreio->order_id);
        $this->assertSame('em_transito', $rastreio->situacao);
        $this->assertSame('2026-10-15 16:31:56', $rastreio->postado_em->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-28', $rastreio->previsao_entrega->toDateString());
        $this->assertNull($rastreio->encerrado_em);
        $this->assertSame(['RO', 'PO', 'FC'], $rastreio->eventos->pluck('codigo')->all());
        $this->assertSame('CAJAMAR', $rastreio->eventos[0]->destino_cidade);
        // Aviso de postagem: uma vez.
        $this->assertCount(1, $this->whatsapps());
        $this->assertNotNull($order->fresh()->whatsapp_enviado_em);
    }

    public function test_entregue_conclui_avisa_uma_vez_e_nao_duplica_eventos(): void
    {
        $this->objetos = ['AE018212819BR' => self::entregue()];
        $order = $this->pedido('AE018212819BR', ['status' => Order::STATUS_SHIPPED, 'whatsapp_enviado_em' => now()->subDay()]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->whatsapp_entregue_em);
        $this->assertCount(1, $this->whatsapps());
        $this->assertSame('pedido_entregue', $this->whatsapps()[0][0]['template']['name']);
        $rastreio = Rastreio::query()->where('codigo', 'AE018212819BR')->firstOrFail();
        $this->assertSame('entregue', $rastreio->situacao);
        $this->assertNotNull($rastreio->encerrado_em);
        $this->assertSame('2026-10-15 19:26:47', $rastreio->entregue_em->format('Y-m-d H:i:s'));
        $this->assertSame(5, RastreioEvento::query()->count());

        // Mesmo evento de novo (pedido volta a "enviado" à força e é
        // consultado direto): nada duplica, WhatsApp não sai de novo.
        $order->forceFill(['status' => Order::STATUS_SHIPPED])->saveQuietly();
        Cache::flush();
        $this->artisan('loja:acompanhar-rastreio', ['--pedido' => $order->id])->assertSuccessful();
        $this->assertSame(5, RastreioEvento::query()->count());
        $this->assertCount(1, $this->whatsapps());

        $this->assertSame(0, app(RegistroDeRastreio::class)->registrar('AE018212819BR', $order->id, self::entregue()));
        $this->assertSame(5, RastreioEvento::query()->count());
    }

    public function test_nao_grava_dado_pessoal_do_recebedor(): void
    {
        app(RegistroDeRastreio::class)->registrar('AE018212819BR', null, self::entregue());

        $colunas = array_keys(RastreioEvento::query()->firstOrFail()->getAttributes());
        foreach (['recebedor', 'latitude', 'longitude', 'destinatario', 'remetente', 'carteiro'] as $proibida) {
            $this->assertNotContains($proibida, $colunas);
        }
        $this->assertStringNotContainsString('NAO GRAVA', json_encode(RastreioEvento::query()->get()->toArray()));
    }

    public function test_chave_desligada_nao_manda_whatsapp_mas_atualiza_status(): void
    {
        config(['services.correios.rastreio_whatsapp' => false]);
        $this->objetos = ['AE018212819BR' => self::entregue()];
        $order = $this->pedido('AE018212819BR', ['status' => Order::STATUS_SHIPPED]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertNull($order->fresh()->whatsapp_entregue_em);
        $this->assertCount(0, $this->whatsapps());
        $this->assertSame(5, RastreioEvento::query()->count());
    }

    public function test_devolvido_encerra_e_nao_e_consultado_de_novo(): void
    {
        Notification::fake();
        // Destino Petrópolis, "entregue" em São Paulo: devolução ao remetente.
        $this->objetos = ['AE018212819BR' => self::entregue()];
        $order = $this->pedido('AE018212819BR', ['status' => Order::STATUS_SHIPPED, 'shipping_city' => 'Petrópolis', 'shipping_state' => 'RJ']);

        $this->artisan('loja:acompanhar-rastreio', ['--sem-outros-canais' => true])->assertSuccessful();
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
        $rastreio = Rastreio::query()->where('codigo', 'AE018212819BR')->firstOrFail();
        $this->assertSame('devolvido', $rastreio->situacao);
        $this->assertNotNull($rastreio->encerrado_em);
        $this->assertCount(0, $this->whatsapps());

        Cache::flush();
        $this->artisan('loja:acompanhar-rastreio', ['--sem-outros-canais' => true])->assertSuccessful();
        $this->assertCount(1, $this->chamadasCorreios());
    }

    public function test_outros_canais_so_guardam_historico(): void
    {
        $this->objetos = ['AE018212819BR' => self::entregue()];
        $amazon = $this->pedido('AE018212819BR', ['origin' => Order::ORIGIN_AMAZON]);

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $this->assertSame(Order::STATUS_PAID, $amazon->fresh()->status);
        $this->assertCount(0, $this->whatsapps());
        $rastreio = Rastreio::query()->where('codigo', 'AE018212819BR')->firstOrFail();
        $this->assertSame($amazon->id, $rastreio->order_id);
        $this->assertSame('entregue', $rastreio->situacao);

        // Encerrado: a próxima rodada não consulta.
        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        $this->assertCount(1, $this->chamadasCorreios());
    }

    public function test_objeto_nao_encontrado_guarda_o_erro(): void
    {
        $order = $this->pedido('AA000000000BR');

        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $rastreio = Rastreio::query()->where('codigo', 'AA000000000BR')->firstOrFail();
        $this->assertSame('erro', $rastreio->situacao);
        $this->assertStringContainsString('SRO-020', $rastreio->ultimo_erro);
    }

    public function test_tela_do_admin_lista_os_rastreios(): void
    {
        $order = $this->pedido('AE018212819BR');
        app(RegistroDeRastreio::class)->registrar('AE018212819BR', $order->id, self::entregue());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/logistica/rastreio?busca=AE0182')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Admin/Logistica/Rastreio', false)
                ->where('rastreios.data.0.codigo', 'AE018212819BR')
                ->where('rastreios.data.0.situacao_rotulo', 'Entregue')
                ->where('rastreios.data.0.pedido.id', $order->id)
                ->has('rastreios.data.0.eventos', 5)
                ->where('whatsappLigado', true));
    }
}
