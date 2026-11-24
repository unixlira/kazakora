<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\EntregaExpressa;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Nomes de entrega que o cliente vê (pedido do Lira 2026-11-24, a pedido do
 * professor de tráfego): nada de Full, Flex ou Turbo na loja. Grátis = "Entrega
 * Rápida" (ou "Entrega Normal"); paga = "Entrega Expressa": motoboy em até 4
 * horas na Grande SP (R$ 39,00 menos o frete que já está no preço) ou SEDEX
 * pela diferença no resto do Brasil. Toda opção diz quando chega.
 */
class EntregaRapidaExpressaTest extends TestCase
{
    use RefreshDatabase;

    private const TERMOS_INTERNOS = '/\b(flex|full|turbo)\b/i';

    private const CEP_CE = '60025-000';

    private const CEP_SP = '01310-100';

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        // Terça, 24/11/2026, 10h em Brasília: dentro do horário do motoboy e antes do corte das 13h.
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'America/Sao_Paulo'));
        config([
            'frete_embutido.ativo' => true,
            'frete_embutido.fixo_na_navegacao' => false,
            'frete_embutido.folga_dias' => 2,
            'frete_embutido.transportadoras_melhor_envio' => [],
            'entrega_expressa.horario_entrega' => '21h',
            'entrega_expressa.motoboy.ignorar_horario' => false,
            'entrega_expressa.motoboy.valor_sp' => 39.0,
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        $this->comCorreios();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Ceará: PAC R$ 20 em 8 dias, SEDEX R$ 30 em 3. São Paulo: SEDEX R$ 9,94 (mais barato que o PAC). */
    private function comCorreios(): void
    {
        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '03187-040', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        config([
            'services.correios.numero_usuario' => '123',
            'services.correios.codigo_acesso_contrato' => 'token-contrato',
            'services.correios.preco_base_url' => 'https://correios.test/preco',
            'services.correios.prazo_base_url' => 'https://correios.test/prazo',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'viacep.com.br')) {
                return Http::response([]);
            }
            if (! str_contains($request->url(), 'correios.test')) {
                return null;
            }
            $sp = fn ($p) => str_starts_with($p['cepDestino'], '0');
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => $p['coProduto'] === '03220' ? ($sp($p) ? '9,94' : '30,00') : ($sp($p) ? '15,45' : '20,00'),
                ])->all());
            }

            return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                'prazoEntrega' => $p['coProduto'] === '03220' ? 3 : 8,
            ])->all());
        });
    }

    private function produto(float $preco = 100): Product
    {
        return Product::factory()->create(['price' => $preco, 'stock' => 5, 'is_active' => true]);
    }

    private function entrega(string $cep, array $extra = []): array
    {
        return array_merge([
            'shipping_method_id' => $this->frete->id,
            'guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000'],
            'new_address' => [
                'recipient_name' => 'Maria Cliente', 'phone' => '(11) 99999-0000', 'zip' => $cep,
                'street' => 'Rua A', 'number' => '20', 'neighborhood' => 'Centro', 'city' => 'Cidade', 'state' => 'SP',
            ],
        ], $extra);
    }

    private function pagarComPix(int $id): void
    {
        $this->mock(MercadoPagoPaymentService::class, function ($mock) use ($id) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => $id, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });
    }

    /** Todo texto (valor) da resposta, para conferir que nenhum termo interno aparece. */
    private function semTermosInternos(mixed $dados, string $onde): void
    {
        $textos = [];
        array_walk_recursive($dados, function ($valor) use (&$textos) {
            if (is_string($valor)) {
                $textos[] = $valor;
            }
        });

        foreach ($textos as $texto) {
            $this->assertDoesNotMatchRegularExpression(self::TERMOS_INTERNOS, $texto, "{$onde}: \"{$texto}\"");
        }
    }

    public function test_pagina_do_produto_fora_de_sp_mostra_entrega_normal_e_expressa_pela_diferenca_do_sedex(): void
    {
        $produto = $this->produto(100);

        $resposta = $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id)->assertOk();

        $resposta
            ->assertJsonPath('motoboy', null)
            ->assertJsonPath('entregas.0.id', 'gratis')
            ->assertJsonPath('entregas.0.nome', 'Entrega Normal')
            // PAC em 8 dias + 2 de postagem.
            ->assertJsonPath('entregas.0.chega', 'Chega em até 10 dias úteis')
            ->assertJsonPath('entregas.0.preco', 0)
            ->assertJsonPath('entregas.1.id', 'expressa')
            ->assertJsonPath('entregas.1.nome', 'Entrega Expressa')
            ->assertJsonPath('entregas.1.tipo', 'sedex')
            // Só a diferença: SEDEX R$ 30 menos o PAC de R$ 20 que já está no preço.
            ->assertJsonPath('entregas.1.preco', 10)
            ->assertJsonPath('entregas.1.excedente', true)
            // SEDEX em 3 dias úteis a partir de terça, 24/11.
            ->assertJsonPath('entregas.1.chega', 'Chega até sex., 27/11');
        $this->assertCount(2, $resposta->json('entregas'));
        $this->semTermosInternos($resposta->json('entregas'), '/frete/prazo');
    }

    public function test_checkout_fora_de_sp_cobra_so_a_diferenca_do_sedex_no_pedido(): void
    {
        Mail::fake();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        $resposta = $this->getJson('/finalizacao/entrega-gratis?cep=60025000')->assertOk()
            ->assertJsonPath('motoboy', null)
            ->assertJsonPath('entregas.1.nome', 'Entrega Expressa')
            ->assertJsonPath('entregas.1.tipo', 'sedex')
            ->assertJsonPath('entregas.1.preco', 10)
            ->assertJsonPath('entregas.1.chega', 'Chega até sex., 27/11');
        $this->semTermosInternos($resposta->json('entregas'), '/finalizacao/entrega-gratis');

        $this->pagarComPix(91);
        $this->postJson('/finalizacao/entrega', $this->entrega(self::CEP_CE, ['turbo' => true]))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::with('items')->firstOrFail();
        $this->assertEquals(10.0, (float) $order->shipping_cost);
        $this->assertEquals(120.0, (float) $order->items->first()->product_price);
        // Admin segue com o nome técnico; o cliente vê "Entrega Expressa".
        $this->assertSame(FreteEmbutido::TURBO, $order->shipping_carrier_name);
        $this->assertSame('Entrega Expressa (SEDEX)', $order->transportadoraParaCliente());
    }

    public function test_grande_sp_mostra_entrega_rapida_hoje_e_motoboy_pelo_excedente(): void
    {
        $produto = $this->produto(100);

        $resposta = $this->getJson('/frete/prazo?cep=01310100&produto='.$produto->id)->assertOk();
        $embutido = round((float) $resposta->json('final_price') - 100, 2);

        $resposta
            ->assertJsonPath('entregas.0.nome', 'Entrega Rápida')
            ->assertJsonPath('entregas.0.chega', 'Chega hoje até as 21h')
            ->assertJsonPath('entregas.0.preco', 0)
            ->assertJsonPath('entregas.1.nome', 'Entrega Expressa')
            ->assertJsonPath('entregas.1.tipo', 'motoboy')
            ->assertJsonPath('entregas.1.chega', 'Chega em até 4 horas')
            ->assertJsonPath('motoboy.valor', 39);
        $this->assertGreaterThan(0, $embutido);
        // R$ 39,00 menos o frete que já está no preço.
        $this->assertEqualsWithDelta(round(39 - $embutido, 2), (float) $resposta->json('entregas.1.preco'), 0.001);
        $this->assertCount(2, $resposta->json('entregas'));
        $this->semTermosInternos($resposta->json('entregas'), '/frete/prazo SP');
    }

    public function test_motoboy_sai_gratis_quando_o_frete_do_preco_cobre_o_valor(): void
    {
        config(['entrega_expressa.motoboy.valor_sp' => 5.0]);
        $produto = $this->produto(100);

        $this->getJson('/frete/prazo?cep=01310100&produto='.$produto->id)->assertOk()
            ->assertJsonPath('entregas.1.tipo', 'motoboy')
            ->assertJsonPath('entregas.1.preco', 0)
            ->assertJsonPath('motoboy.preco', 0);

        $servico = app(EntregaExpressa::class);
        $this->assertSame(0.0, $servico->motoboyGrandeSp(self::CEP_SP, 39.0)['preco']);
        $this->assertSame(0.0, $servico->motoboyGrandeSp(self::CEP_SP, 45.0)['preco']);
        $this->assertSame(5.0, $servico->motoboyGrandeSp(self::CEP_SP, 0.0)['preco']);
    }

    public function test_motoboy_so_na_grande_sp_em_horario_comercial_e_com_valor_configurado(): void
    {
        $servico = app(EntregaExpressa::class);
        $terca = Carbon::parse('2026-11-24 10:00', 'America/Sao_Paulo');

        // Valor padrão R$ 39,00, cliente paga só o excedente.
        $this->assertSame(['preco' => 29.0, 'valor' => 39.0, 'mensagem' => 'Chega em até 4 horas'], $servico->motoboyGrandeSp('01001-000', 10.0, $terca));
        $this->assertSame(39.0, $servico->motoboyGrandeSp('01001-000', 0.0, $terca)['preco']);

        // Grande SP inteira: CEP 01000-000 a 09999-999 (Guarulhos e Mauá também).
        foreach (['01000-000', '07010-000', '09310-000', '09999-999'] as $cep) {
            $this->assertNotNull($servico->motoboyGrandeSp($cep, 0.0, $terca), $cep);
        }
        foreach (['00999-999', '10000-000', '20040-020', '60025-000', '123'] as $cep) {
            $this->assertNull($servico->motoboyGrandeSp($cep, 0.0, $terca), $cep);
        }

        // Fora do horário comercial (domingo ou depois das 18h): sem motoboy.
        $this->assertNull($servico->motoboyGrandeSp('01001-000', 0.0, Carbon::parse('2026-11-29 10:00', 'America/Sao_Paulo')));
        $this->assertNull($servico->motoboyGrandeSp('01001-000', 0.0, Carbon::parse('2026-11-24 18:00', 'America/Sao_Paulo')));

        // Sem valor configurado: desligado.
        config(['entrega_expressa.motoboy.valor_sp' => 0.0]);
        $this->assertNull($servico->motoboyGrandeSp('01001-000', 0.0, $terca));
        $produto = $this->produto(100);
        $resposta = $this->getJson('/frete/prazo?cep=01310100&produto='.$produto->id)->assertOk()->assertJsonPath('motoboy', null);
        $this->assertNotContains('motoboy', array_column($resposta->json('entregas'), 'tipo'));
    }

    public function test_pedido_com_motoboy_cobra_o_excedente_calculado_no_servidor(): void
    {
        Mail::fake();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $checkout = $this->getJson('/finalizacao/entrega-gratis?cep=01310100')->assertOk()
            ->assertJsonPath('entregas.0.nome', 'Entrega Rápida')
            ->assertJsonPath('entregas.1.tipo', 'motoboy')
            ->json();
        $preco = (float) $checkout['entregas'][1]['preco'];
        $this->assertGreaterThan(0, $preco);
        $this->assertLessThan(39, $preco);

        $this->pagarComPix(92);
        $this->postJson('/finalizacao/entrega', $this->entrega(self::CEP_SP, ['motoboy' => true]))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::firstOrFail();
        $this->assertEqualsWithDelta($preco, (float) $order->shipping_cost, 0.001);
        $this->assertSame(CheckoutController::MOTOBOY, $order->shipping_carrier_name);
        $this->assertSame('Entrega Expressa', $order->transportadoraParaCliente());
    }

    public function test_motoboy_pedido_fora_da_grande_sp_segue_no_gratis(): void
    {
        Mail::fake();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        $this->pagarComPix(93);
        $this->postJson('/finalizacao/entrega', $this->entrega(self::CEP_CE, ['motoboy' => true]))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $this->assertEquals(0.0, (float) Order::firstOrFail()->shipping_cost);
    }

    public function test_nomes_de_transportadora_para_o_cliente(): void
    {
        $this->assertSame('Entrega Expressa (SEDEX)', Order::nomeDaEntregaParaCliente('SEDEX (TURBO)'));
        $this->assertSame('Entrega Expressa', Order::nomeDaEntregaParaCliente('Motoboy (Entrega Expressa)'));
        $this->assertSame('Entrega Rápida', Order::nomeDaEntregaParaCliente('Full'));
        $this->assertSame('Entrega Rápida', Order::nomeDaEntregaParaCliente('Mercado Envios Flex'));
        $this->assertSame('JeT Standard', Order::nomeDaEntregaParaCliente('JeT Standard'));
        $this->assertNull(Order::nomeDaEntregaParaCliente(null));
    }

    public function test_politica_de_entrega_sem_termos_internos_e_com_a_entrega_expressa(): void
    {
        $resposta = $this->get('/politica-de-entrega')->assertOk();
        $props = $resposta->viewData('page')['props'];

        $this->assertSame(39.0, (float) $props['motoboy']['preco']);
        $this->assertArrayHasKey('rapida', $props);
        $this->semTermosInternos([$props['rapida'], $props['motoboy']], '/politica-de-entrega');
    }

    /**
     * Telas e e-mails do cliente: nenhum Full, Flex ou Turbo no texto (o
     * template, sem os comentários; classes CSS ficam em minúsculas e não contam).
     */
    public function test_telas_e_emails_do_cliente_sem_full_flex_ou_turbo(): void
    {
        $arquivos = [
            'resources/js/Shared/Layouts/AppLayout.vue',
            'resources/js/Shared/Components/ProductCard.vue',
            'resources/js/Modules/Catalog/ProductDetailV2.vue',
            'resources/js/Modules/Checkout/CheckoutV2.vue',
            'resources/js/Modules/Checkout/Delivery.vue',
            'resources/js/Modules/Checkout/OrderDetail.vue',
            'resources/js/Modules/Checkout/Rastreio.vue',
            'resources/js/Modules/Cart/Index.vue',
            'resources/js/Modules/Legal/Entrega.vue',
            'resources/js/Modules/Legal/Termos.vue',
            'resources/views/emails/layout.blade.php',
            'resources/views/emails/welcome.blade.php',
            'resources/views/emails/order-confirmation.blade.php',
            'resources/views/emails/pedido-enviado.blade.php',
        ];

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents(base_path($arquivo));
            if (str_ends_with($arquivo, '.vue')) {
                $conteudo = substr($conteudo, (int) strpos($conteudo, '<template>'));
                $conteudo = preg_replace('/<style[\s\S]*$/', '', $conteudo);
            }
            $conteudo = preg_replace(['/<!--[\s\S]*?-->/', '/\{\{--[\s\S]*?--\}\}/'], '', $conteudo);

            $this->assertDoesNotMatchRegularExpression('/\b(Flex|FLEX|Full|FULL|Turbo|TURBO)\b/', $conteudo, $arquivo);
        }

        // Faixa do topo: só "Frete grátis | Compra 100% segura".
        // Desde 2026-11-24 as frases vêm do servidor (FaixaDoTopo), não mais escritas no layout.
        $faixa = file_get_contents(base_path('app/Modules/Catalog/Support/FaixaDoTopo.php'));
        $this->assertStringContainsString("'Frete grátis'", $faixa);
        $this->assertStringContainsString("'Compra 100% segura'", $faixa);
        $layout = file_get_contents(base_path('resources/js/Shared/Layouts/AppLayout.vue'));
        $this->assertStringNotContainsString('Receba no mesmo dia', $layout.$faixa);
    }
}
