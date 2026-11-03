<?php

namespace Tests\Feature\Admin;

use App\Models\MelhorEnvioToken;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\PreencherTabelaFreteJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\LojaShippingDriver;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Support\FreteProvedor;
use App\Support\SiteAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/**
 * Logística: Correios ou Melhor Envio, só um conectado (pedido do Lira em
 * 2026-11-03). Conectar um desconecta o outro, troca a tabela de frete do
 * banco lida pelo site, o preço do site e a etiqueta dos pedidos novos.
 */
class LogisticaProvedorTest extends TestCase
{
    use RefreshDatabase;

    private const ME = 'https://me.test/api/v2';

    private const ME_ID = '9d3c6a51-1111-4222-8333-944455556666';

    private const ES = [
        ['numero' => 18001, 'uf' => 'ES', 'localidade' => 'Água Doce do Norte', 'cep' => '29820000', 'tipo' => 'M', 'codigoIbge' => '3200169', 'faixas' => [['cepInicial' => '29820000', 'cepFinal' => '29829999', 'tipoFaixa' => 'T']]],
        ['numero' => 19901, 'uf' => 'ES', 'localidade' => 'Santa Luzia do Azul', 'cep' => '29823000', 'numeroLocalidadeSuperior' => 18001, 'localidadeSuperior' => 'Água Doce do Norte', 'tipo' => 'D'],
    ];

    /** Token do Melhor Envio que não renova (o caso da produção em 03/11). */
    private bool $refreshRecusado = false;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107', 'inscricao_estadual' => '123456789',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '03187-040', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        config([
            'frete_embutido.ativo' => true,
            'frete_embutido.cep_fixo' => '29823000',
            'services.correios.numero_usuario' => '123',
            'services.correios.codigo_acesso_contrato' => 'token-contrato',
            'services.correios.preco_base_url' => 'https://correios.test/preco',
            'services.correios.prazo_base_url' => 'https://correios.test/prazo',
            'services.correios.cep_base_url' => 'https://correios.test/cep',
            'services.melhorenvio.api_base_url' => self::ME,
            'services.melhorenvio.token_url' => 'https://me.test/oauth/token',
        ]);

        // Correios: R$ 10/kg no PAC (prazo 7) e o dobro no SEDEX (prazo 3).
        // Melhor Envio: Jadlog R$ 8/kg (6 dias) e Loggi R$ 12/kg (2 dias).
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/oauth/token')) {
                return $this->refreshRecusado
                    ? Http::response(['error' => 'invalid_request', 'message' => 'The refresh token is invalid.'], 401)
                    : Http::response(['access_token' => 'novo', 'refresh_token' => 'r2', 'expires_in' => 2592000]);
            }
            if (str_contains($url, '/cep/v1/localidades')) {
                return Http::response(['itens' => self::ES, 'page' => ['totalPages' => 1]]);
            }
            if (str_contains($url, '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => number_format(((int) $p['psObjeto'] / 100) * ($p['coProduto'] === TabelaFreteLocalidades::SEDEX ? 2 : 1), 2, ',', ''),
                ])->all());
            }
            if (str_contains($url, '/prazo/')) {
                return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'prazoEntrega' => $p['coProduto'] === TabelaFreteLocalidades::SEDEX ? 3 : 7,
                ])->all());
            }
            if (str_contains($url, '/me/shipment/calculate')) {
                $kg = (float) $request['package']['weight'];

                return Http::response([
                    ['id' => 3, 'name' => '.Package', 'price' => number_format($kg * 8, 2, '.', ''), 'delivery_time' => 6, 'company' => ['name' => 'Jadlog']],
                    ['id' => 31, 'name' => 'Express', 'price' => number_format($kg * 12, 2, '.', ''), 'delivery_time' => 2, 'company' => ['name' => 'Loggi']],
                    ['id' => 17, 'name' => 'Mini Envios', 'error' => 'Dimensões acima do limite', 'company' => ['name' => 'Correios']],
                ]);
            }
            if (str_contains($url, '/me/cart')) {
                return Http::response(['id' => self::ME_ID, 'protocol' => 'ORD-2026110300001', 'price' => 3.6, 'status' => 'pending'], 201);
            }
            if (str_contains($url, '/me/shipment/checkout')) {
                return Http::response(['purchase' => ['id' => 'p1', 'total' => 3.6, 'status' => 'paid']]);
            }
            if (str_contains($url, '/me/shipment/generate')) {
                return Http::response([self::ME_ID => ['status' => true, 'message' => 'Envio gerado com sucesso']]);
            }
            if (str_contains($url, '/me/shipment/tracking')) {
                return Http::response([self::ME_ID => ['id' => self::ME_ID, 'status' => 'released', 'tracking' => 'JD123BR']]);
            }
            if (str_contains($url, '/me/imprimir/pdf/')) {
                return Http::response(json_encode('https://arquivos.me.test/etiqueta.pdf'), 200, ['Content-Type' => 'application/json']);
            }
            if (str_contains($url, 'arquivos.me.test')) {
                return Http::response('%PDF-1.4 etiqueta', 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response([], 404);
        });

        $tabela = app(TabelaFreteLocalidades::class);
        $tabela->importarUf('ES');
        $tabela->cotar($tabela->cepsPendentes(100));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function contaMelhorEnvio(bool $vencida = false): void
    {
        MelhorEnvioToken::create([
            'account_label' => 'KazaKora ME', 'access_token' => 'acesso', 'refresh_token' => 'r1',
            'token_expires_at' => $vencida ? now()->subMonths(2) : now()->addDays(20), 'scopes' => [],
        ]);
    }

    public function test_correios_e_o_padrao_e_token_vencido_nao_aparece_conectado(): void
    {
        $this->contaMelhorEnvio(vencida: true);
        $this->refreshRecusado = true;

        $this->actingAs($this->admin())->get('/admin/logistica')
            ->assertInertia(fn (Assert $page) => $page
                ->where('provedorAtivo', 'correios')
                ->where('provedores.0.conectado', true)
                ->where('provedores.1.conectado', false)
                ->where('provedores.1.disponivel', false)
                ->where('provedores.1.aviso', 'O acesso à conta venceu: "Conectar" abre o login do Melhor Envio de novo.')
                // Tabela da tela = Correios do CEP de referência (Pequena 1 kg: PAC 10, SEDEX 20).
                ->where('tabela.linhas.0.barato', 10)
                ->where('tabela.linhas.0.rapido', 20));
    }

    public function test_conectar_melhor_envio_desconecta_correios_e_troca_tabela_e_preco(): void
    {
        $this->contaMelhorEnvio();
        $produto = Product::factory()->create(['categoria_frete' => 'pequena']);
        FreteEmbutido::esquecerCategorias();

        $this->assertEquals(10.0, $this->freteDoSite($produto));

        $this->actingAs($this->admin())->post('/admin/logistica/provedor/melhor_envio/conectar')
            ->assertSessionHas('success', 'Melhor Envio conectado e Correios desconectado. O resto da tabela de frete está sendo preenchido em segundo plano.');
        // O outro CEP (Água Doce do Norte) vai pela fila, no ritmo da API.
        Queue::assertPushed(PreencherTabelaFreteJob::class);

        $this->assertSame(FreteProvedor::MELHOR_ENVIO, FreteProvedor::escolhido());
        // Linhas do Melhor Envio gravadas para o CEP de referência; as dos Correios ficam guardadas.
        $me = DB::table('frete_tabela')->where('provedor', 'melhor_envio')->where('categoria', 'pequena')->first();
        $this->assertEquals(8.0, (float) $me->pac_preco);
        $this->assertSame('Jadlog Package', $me->pac_servico);
        $this->assertEquals(12.0, (float) $me->sedex_preco);
        $this->assertSame('Loggi Express', $me->sedex_servico);
        $this->assertSame(1, DB::table('frete_tabela')->where('provedor', 'correios')->where('cep_destino', '29823000')->where('categoria', 'pequena')->count());

        // Preço do site passa a somar o Melhor Envio na hora.
        $this->assertEquals(8.0, $this->freteDoSite($produto));

        $this->get('/admin/logistica')->assertInertia(fn (Assert $page) => $page
            ->where('provedorAtivo', 'melhor_envio')
            ->where('provedores.0.conectado', false)
            ->where('provedores.1.conectado', true)
            ->where('tabela.linhas.0.barato', 8)
            ->where('tabela.linhas.0.barato_servico', 'Jadlog Package'));

        // E volta: Correios de novo, sem cotar outra vez.
        $this->post('/admin/logistica/provedor/correios/conectar')
            ->assertSessionHas('success', 'Correios conectado e Melhor Envio desconectado.');
        $this->assertSame(FreteProvedor::CORREIOS, FreteProvedor::escolhido());
        $this->assertEquals(10.0, $this->freteDoSite($produto));
    }

    public function test_melhor_envio_sem_conta_valida_vai_ao_login_e_conecta_na_volta(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/logistica/provedor/melhor_envio/conectar', [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', '/api/melhorenvio/auth');

        $this->assertSame(FreteProvedor::CORREIOS, FreteProvedor::escolhido());
        $this->assertTrue(session('frete.conectar_melhor_envio'));
    }

    public function test_ferrakora_continua_nos_correios(): void
    {
        $this->contaMelhorEnvio();
        FreteProvedor::ativar(FreteProvedor::MELHOR_ENVIO);

        $this->assertSame(FreteProvedor::MELHOR_ENVIO, FreteProvedor::ativo());
        $site = app(SiteAtual::class);
        $this->assertSame(FreteProvedor::CORREIOS, $site->durante(SiteAtual::FERRAKORA, fn () => FreteProvedor::ativo()));
        $this->assertSame(FreteProvedor::CORREIOS, FreteProvedor::doPedido(SiteAtual::FERRAKORA));
        $this->assertSame(FreteProvedor::MELHOR_ENVIO, FreteProvedor::doPedido(null));
    }

    public function test_pedido_da_kazakora_sai_com_etiqueta_do_melhor_envio_uma_vez_so(): void
    {
        config(['services.j3.site' => false]);
        $this->contaMelhorEnvio();
        FreteProvedor::ativar(FreteProvedor::MELHOR_ENVIO);
        $correios = Mockery::mock(CorreiosAutoShipping::class);
        $correios->shouldReceive('geradaPara')->andReturnNull();
        $correios->shouldNotReceive('confirm');
        $this->app->instance(CorreiosAutoShipping::class, $correios);

        $order = $this->pedidoDoSite();
        $driver = app(LojaShippingDriver::class);

        $envio = $driver->confirmShipping($order);
        $this->assertSame(self::ME_ID, $envio['external_shipment_id']);
        $this->assertSame('JD123BR', $envio['tracking_code']);
        $this->assertSame('Melhor Envio Jadlog Package', $envio['shipping_method']);

        $registro = MelhorEnvioEnvio::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(MelhorEnvioEnvio::STATUS_GERADA, $registro->status);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me/cart')
            && $r['service'] === 3
            && $r['options']['invoice']['key'] === '35261065604590000107550020000031181569237956'
            && $r['from']['company_document'] === '65604590000107'
            && $r['to']['document'] === '12345678909');

        // Nova tentativa não compra de novo.
        $driver->confirmShipping($order->fresh());
        Http::assertSentCount(collect(Http::recorded())->count());
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/me/shipment/checkout'))->count());

        $etiqueta = $driver->fetchLabel($order);
        $this->assertTrue($etiqueta['ready']);
        $this->assertStringStartsWith('%PDF', $etiqueta['contents']);
    }

    private function freteDoSite(Product $produto): float
    {
        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        $frete->usarCep('29823000');

        return (float) $frete->paraProduto($produto->fresh())['embutido'];
    }

    private function pedidoDoSite(): Order
    {
        $cliente = User::factory()->create(['cpf' => '123.456.789-09', 'email' => 'cliente@example.com']);
        $produto = Product::factory()->create(['sku' => 'KZ-1']);
        ProductFiscalData::create(['product_id' => $produto->id, 'peso_bruto' => 0.45, 'altura_cm' => 10, 'largura_cm' => 12, 'profundidade_cm' => 9]);

        $order = Order::create([
            'user_id' => $cliente->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'external_order_id' => '261103ME1', 'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999',
            'shipping_zip' => '29823-000', 'shipping_street' => 'Rua X', 'shipping_number' => '10',
            'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Água Doce do Norte', 'shipping_state' => 'ES',
            'shipping_cost' => 0, 'subtotal' => 129.90, 'total' => 129.90,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'Produto teste', 'product_price' => 129.90, 'quantity' => 1, 'subtotal' => 129.90]);
        Invoice::create([
            'order_id' => $order->id, 'origem' => Invoice::ORIGEM_PEDIDO, 'status' => Invoice::STATUS_AUTHORIZED,
            'ambiente' => Invoice::AMBIENTE_PRODUCAO, 'serie' => 2, 'numero' => 3118, 'valor_total' => 129.90,
            'chave_acesso' => '35261065604590000107550020000031181569237956',
        ]);

        return $order;
    }
}
