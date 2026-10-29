<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Modules\Marketplace\Support\CorreiosLabelPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pré-postagem de DEVOLUÇÃO (Lira 2026-10-29, pedido 1821): a cliente é o
 * remetente e a KazaKora o destinatário; não pode virar envio do pedido.
 */
class CorreiosDevolucaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function empresa(): Company
    {
        return Company::create([
            'razao_social' => 'Kazakora Comércio Ltda',
            'nome_fantasia' => 'KazaKora',
            'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL,
            'phone' => '(11) 91234-5678',
            'email' => 'contato@kazakora.com',
            'zip' => '01000-000',
            'street' => 'Rua da Loja',
            'number' => '100',
            'neighborhood' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);
    }

    private function pedido(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_COMPLETED,
            'shipping_name' => 'Elci Cliente', 'shipping_phone' => '19999990000', 'shipping_zip' => '13480-000',
            'shipping_street' => 'Rua da Cliente', 'shipping_number' => '25', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Limeira', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));
        $order->items()->create(['product_name' => 'Power Bank', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $order;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer' => ['name' => 'Elci Cliente', 'document' => '111.222.333-44', 'phone' => '(19) 99999-0000', 'email' => 'elci@example.com'],
            'address' => ['zip' => '13480-000', 'street' => 'Rua da Cliente', 'number' => '25', 'neighborhood' => 'Centro', 'city' => 'Limeira', 'state' => 'SP'],
            'service_code' => '03298',
            'weight_grams' => 400,
            'dimensions' => ['format' => '2', 'height' => 5, 'width' => 10, 'length' => 15],
            'content_items' => [['conteudo' => 'Power Bank', 'quantidade' => 1, 'valor' => 100]],
        ], $overrides);
    }

    private function fakeCorreios(): void
    {
        config(['services.correios.numero_usuario' => '65604590000107', 'services.correios.codigo_acesso' => 'token-teste']);

        Http::fake([
            '*/token/v1/autentica' => Http::response(['token' => 'bearer-abc'], 201),
            '*/prepostagem/v1/prepostagens' => Http::response(['id' => '777', 'codigoObjeto' => 'OD000000001BR'], 201),
            '*' => Http::response([], 500),
        ]);
    }

    /** @return array<string, mixed> JSON enviado no POST /v1/prepostagens */
    private function jsonEnviadoAosCorreios(): array
    {
        $enviado = Http::recorded(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/prepostagens'));
        $this->assertCount(1, $enviado);

        return $enviado->first()[0]->data();
    }

    public function test_devolucao_manda_cliente_como_remetente_e_empresa_como_destinatario(): void
    {
        $this->empresa();
        $this->fakeCorreios();
        $order = $this->pedido();

        $this->actingAs($this->admin())
            ->post('/admin/correios', $this->payload(['order_id' => $order->id, 'devolucao' => true]))
            ->assertRedirect();

        $json = $this->jsonEnviadoAosCorreios();

        $this->assertSame('Elci Cliente', $json['remetente']['nome']);
        $this->assertSame('11122233344', $json['remetente']['cpfCnpj']);
        $this->assertSame('13480000', $json['remetente']['endereco']['cep']);
        $this->assertSame('Limeira', $json['remetente']['endereco']['cidade']);
        $this->assertSame('KazaKora', $json['destinatario']['nome']);
        $this->assertSame('65604590000107', $json['destinatario']['cpfCnpj']);
        $this->assertSame('01000000', $json['destinatario']['endereco']['cep']);
        $this->assertSame('03298', $json['codigoServico']);
        // Pré-postagem normal do contrato: sem logística reversa e sem NF-e da venda.
        $this->assertArrayNotHasKey('logisticaReversa', $json);
        $this->assertArrayNotHasKey('chaveNFe', $json);
        $this->assertArrayNotHasKey('devolucao', $json);

        $registro = CorreiosPrePostagem::firstOrFail();
        $this->assertTrue($registro->devolucao);
        $this->assertSame($order->id, $registro->order_id);
        $this->assertSame(CorreiosPrePostagem::STATUS_GERADA, $registro->status);
        $this->assertSame('OD000000001BR', $registro->codigo_objeto);
    }

    public function test_fluxo_normal_continua_loja_para_cliente(): void
    {
        $this->empresa();
        $this->fakeCorreios();

        $this->actingAs($this->admin())->post('/admin/correios', $this->payload())->assertRedirect();

        $json = $this->jsonEnviadoAosCorreios();

        $this->assertSame('KazaKora', $json['remetente']['nome']);
        $this->assertSame('65604590000107', $json['remetente']['cpfCnpj']);
        $this->assertSame('01000000', $json['remetente']['endereco']['cep']);
        $this->assertSame('Elci Cliente', $json['destinatario']['nome']);
        $this->assertSame('13480000', $json['destinatario']['endereco']['cep']);
        $this->assertSame(['remetente', 'destinatario', 'codigoServico', 'pesoInformado', 'cienteObjetoNaoProibido', 'itensDeclaracaoConteudo', 'codigoFormatoObjetoInformado', 'alturaInformada', 'larguraInformada', 'comprimentoInformado'], array_keys($json));
        $this->assertFalse(CorreiosPrePostagem::firstOrFail()->devolucao);
    }

    public function test_devolucao_nao_vira_envio_do_pedido(): void
    {
        $this->empresa();
        $order = $this->pedido();

        CorreiosPrePostagem::create($this->registro($order, ['devolucao' => true, 'codigo_objeto' => 'OD000000001BR', 'postage_price' => 30]));

        $order = $order->fresh();
        $this->assertNull($order->latestCorreiosPrePostagem, 'devolução não é a pré-postagem do pedido');
        $this->assertNull($order->trackingCode(), 'devolução não é o rastreio do pedido');
        $this->assertNull(app(CorreiosAutoShipping::class)->geradaPara($order), 'devolução não é etiqueta para imprimir');
        $this->assertSame(0.0, ContributionMargin::correios(now()->subDay(), now()->addDay()), 'devolução não entra como frete do envio');
        $this->assertSame([], ContributionMargin::turboCobrado(now()->subDay(), now()->addDay()));

        // Rastreio do site não consulta nem avisa nada pela devolução.
        Http::fake();
        $this->artisan('loja:acompanhar-rastreio')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_com_envio_e_devolucao_o_pedido_continua_com_o_envio(): void
    {
        $this->empresa();
        $order = $this->pedido();

        $envio = CorreiosPrePostagem::create($this->registro($order, ['codigo_objeto' => 'OE000000001BR', 'postage_price' => 20]));
        CorreiosPrePostagem::create($this->registro($order, ['devolucao' => true, 'codigo_objeto' => 'OD000000001BR', 'postage_price' => 30]));

        $order = $order->fresh();
        $this->assertSame($envio->id, $order->latestCorreiosPrePostagem->id);
        $this->assertSame('OE000000001BR', $order->trackingCode());
        $this->assertSame($envio->id, app(CorreiosAutoShipping::class)->geradaPara($order)->id);
        $this->assertSame(20.0, ContributionMargin::correios(now()->subDay(), now()->addDay()));
        $this->assertCount(2, $order->correiosPrePostagens, 'as duas aparecem no pedido');
    }

    public function test_botao_abre_formulario_em_modo_devolucao_com_o_pedido(): void
    {
        $this->empresa();
        $order = $this->pedido();

        $this->actingAs($this->admin())
            ->get("/admin/correios/nova?devolucao=1&pedido={$order->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Correios/Create', false)
                ->where('devolucao', true)
                ->where('pedidoInicial', (string) $order->id)
                ->where('sender.name', 'KazaKora'));

        $this->actingAs($this->admin())
            ->get('/admin/correios/nova')
            ->assertInertia(fn ($page) => $page->where('devolucao', false)->where('pedidoInicial', null));
    }

    public function test_pedido_e_telas_dos_correios_mostram_o_selo_devolucao(): void
    {
        $this->empresa();
        $order = $this->pedido();
        $devolucao = CorreiosPrePostagem::create($this->registro($order, ['devolucao' => true, 'codigo_objeto' => 'OD000000001BR']));
        $admin = $this->admin();

        $this->actingAs($admin)->get("/admin/pedidos/{$order->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('detalhe.envio.pre_postagens.0.devolucao', true));

        $this->actingAs($admin)->get('/admin/correios')
            ->assertInertia(fn ($page) => $page->where('items.data.0.devolucao', true));

        $this->actingAs($admin)->get("/admin/correios/{$devolucao->id}")
            ->assertInertia(fn ($page) => $page
                ->where('item.devolucao', true)
                ->where('item.sender.name', 'Elci Cliente')
                ->where('item.recipient.name', 'KazaKora')
                ->where('item.invoice.hasInvoice', false));
    }

    public function test_etiqueta_de_devolucao_tem_kazakora_como_destino_e_sem_danfe(): void
    {
        $this->empresa();
        $order = $this->pedido();
        $devolucao = CorreiosPrePostagem::create($this->registro($order, ['devolucao' => true, 'codigo_objeto' => 'OD000000001BR', 'qr_payload' => 'OD000000001BR']));
        $envio = CorreiosPrePostagem::create($this->registro($order, ['codigo_objeto' => 'OE000000001BR', 'qr_payload' => 'OE000000001BR']));

        $textoDevolucao = $this->textoDoPdf(app(CorreiosLabelPdf::class)->render($devolucao));
        $textoEnvio = $this->textoDoPdf(app(CorreiosLabelPdf::class)->render($envio));

        $this->assertStringContainsString('CEP DESTINAT', $textoDevolucao);
        $this->assertStringContainsString('01000-000', $textoDevolucao);
        $this->assertStringContainsString('DEVOLU', $textoDevolucao);
        $this->assertStringContainsString('Elci Cliente', $textoDevolucao);
        $this->assertStringNotContainsString('DANFE', $textoDevolucao);
        // Destinatário (KazaKora) vem antes do remetente (cliente).
        $this->assertLessThan(strpos($textoDevolucao, 'Elci Cliente'), strpos($textoDevolucao, 'KazaKora · CNPJ'));

        // Etiqueta de envio continua com DANFE e CEP da cliente.
        $this->assertStringContainsString('DANFE', $textoEnvio);
        $this->assertStringContainsString('13480-000', $textoEnvio);
        $this->assertStringNotContainsString('DEVOLU', $textoEnvio);
    }

    /** Texto das streams do PDF (FPDF comprime com zlib). */
    private function textoDoPdf(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);

        return mb_convert_encoding(collect($m[1])->map(fn ($s) => @gzuncompress($s) ?: $s)->implode("\n"), 'UTF-8', 'Windows-1252');
    }

    /** @return array<string, mixed> */
    private function registro(Order $order, array $extra = []): array
    {
        return array_merge([
            'order_id' => $order->id,
            'origin' => $order->origin,
            'customer_name' => 'Elci Cliente',
            'zip' => '13480-000',
            'street' => 'Rua da Cliente',
            'number' => '25',
            'neighborhood' => 'Centro',
            'city' => 'Limeira',
            'state' => 'SP',
            'service_code' => '03298',
            'service_label' => 'PAC (contrato)',
            'weight_grams' => 400,
            'content_items' => [['conteudo' => 'Power Bank', 'quantidade' => 1, 'valor' => 100]],
            'status' => CorreiosPrePostagem::STATUS_GERADA,
        ], $extra);
    }
}
