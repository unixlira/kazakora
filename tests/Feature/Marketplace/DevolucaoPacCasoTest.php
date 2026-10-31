<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Modules\Marketplace\Support\DevolucaoPacCaso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A etiqueta de Devolução PAC abre e controla um caso em Devoluções, e o caso
 * segue o fluxo fiscal normal (Lira 2026-10-31). Nenhuma nota real sai daqui:
 * GenerateInvoiceJob fica sempre no Bus::fake.
 */
class DevolucaoPacCasoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $pedido;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([GenerateInvoiceJob::class]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '01000-000', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        $this->pedido = $this->venda();
        $this->fakeCorreios();
    }

    private function venda(): Order
    {
        $produto = Product::factory()->create();
        ProductFiscalData::create(['product_id' => $produto->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => '85044010']);

        $order = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => Order::STATUS_COMPLETED, 'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-1', 'shipping_name' => 'Cliente', 'shipping_phone' => '1', 'shipping_zip' => '01001000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP',
            'shipping_state' => 'RJ', 'subtotal' => 49.99, 'total' => 49.99,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'Power Bank', 'product_price' => 49.99, 'quantity' => 1, 'subtotal' => 49.99, 'item_type' => OrderItem::TYPE_PRODUCT]);
        Invoice::create([
            'order_id' => $order->id, 'serie' => 2, 'numero' => 1506, 'status' => Invoice::STATUS_AUTHORIZED,
            'chave_acesso' => '35260865604590000107550020000015061077037900', 'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO,
        ]);

        return $order;
    }

    private function fakeCorreios(): void
    {
        config(['services.correios.numero_usuario' => '65604590000107', 'services.correios.codigo_acesso' => 'token-teste']);

        $numero = 0;
        Http::fake(function ($request) use (&$numero) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/token/v1/autentica') => Http::response(['token' => 'bearer-abc'], 201),
                $request->method() === 'DELETE' => Http::response([], 200),
                str_ends_with($url, '/v1/prepostagens') => Http::response(['id' => 'PRX'.(++$numero), 'codigoObjeto' => "OD{$numero}BR"], 201),
                default => Http::response([], 500),
            };
        });
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'order_id' => $this->pedido->id,
            'devolucao' => true,
            'customer' => ['name' => 'Cliente', 'document' => '111.222.333-44', 'phone' => '(19) 99999-0000', 'email' => 'c@example.com'],
            'address' => ['zip' => '13480-000', 'street' => 'Rua da Cliente', 'number' => '25', 'neighborhood' => 'Centro', 'city' => 'Limeira', 'state' => 'SP'],
            'service_code' => '03298',
            'weight_grams' => 400,
            'dimensions' => ['format' => '2', 'height' => 5, 'width' => 10, 'length' => 15],
            'content_items' => [['conteudo' => 'Power Bank', 'quantidade' => 1, 'valor' => 49.99]],
        ], $extra);
    }

    private function gerar(array $extra = []): CorreiosPrePostagem
    {
        $this->actingAs($this->admin)->post('/admin/correios', $this->payload($extra))->assertRedirect();

        return CorreiosPrePostagem::query()->latest('id')->firstOrFail();
    }

    private function cancelar(CorreiosPrePostagem $etiqueta): void
    {
        $this->actingAs($this->admin)->post("/admin/correios/{$etiqueta->id}/cancelar")->assertRedirect();
        $this->assertSame(CorreiosPrePostagem::STATUS_CANCELADA, $etiqueta->fresh()->status);
    }

    private function rascunhos(): int
    {
        return Order::query()->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->count();
    }

    private function casoDaApi(array $extra = []): MarketplaceReturn
    {
        return MarketplaceReturn::create(array_merge([
            'channel' => Order::ORIGIN_MERCADO_LIVRE, 'external_id' => 'CLAIM-1', 'order_id' => $this->pedido->id, 'external_order_id' => 'ML-1',
            'kind' => MarketplaceReturn::KIND_DEVOLUCAO, 'reason_label' => 'Defeito', 'situacao' => MarketplaceReturn::EM_TRANSITO, 'tracking_number' => 'API123', 'manual' => false,
        ], $extra));
    }

    public function test_etiqueta_gerada_abre_um_caso_ligado_ao_pedido_e_ao_rastreio(): void
    {
        $this->gerar();

        $caso = MarketplaceReturn::query()->sole();
        $this->assertSame(Order::ORIGIN_MERCADO_LIVRE, $caso->channel);
        $this->assertSame($this->pedido->id, $caso->order_id);
        $this->assertSame('ML-1', $caso->external_order_id);
        $this->assertSame(MarketplaceReturn::KIND_DEVOLUCAO, $caso->kind);
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->situacao);
        $this->assertSame('OD1BR', $caso->tracking_number);
        $this->assertTrue($caso->manual);
        $this->assertMatchesRegularExpression('/^manual-\d{14}-\d{3}$/', $caso->external_id);
        $this->assertSame(DevolucaoPacCaso::MOTIVO, $caso->reason_label);
        $this->assertNotNull($caso->opened_at);
        $this->assertTrue($caso->events()->where('description', 'like', 'Aberta pela etiqueta%OD1BR%')->exists());
    }

    public function test_abrir_o_caso_monta_o_rascunho_e_nenhuma_nota_nasce(): void
    {
        $notas = Invoice::query()->count();

        $this->gerar();

        $caso = MarketplaceReturn::query()->sole();
        $this->assertSame('preparada', $caso->devolucao_fiscal_status);
        $this->assertSame(1, $this->rascunhos());
        $this->assertSame($notas, Invoice::query()->count());
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_receber_o_produto_dispara_a_nota(): void
    {
        $this->gerar();
        $caso = MarketplaceReturn::query()->sole();

        $this->actingAs($this->admin)->post("/admin/devolucoes/{$caso->id}", ['acao' => 'receber'])->assertRedirect();

        Bus::assertDispatched(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => true);
        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
        $this->assertSame('emitida', $caso->fresh()->devolucao_fiscal_status);
    }

    public function test_tentativa_com_erro_nao_abre_caso(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        config(['services.correios.numero_usuario' => '65604590000107', 'services.correios.codigo_acesso' => 'token-teste']);
        Http::fake([
            '*/token/v1/autentica' => Http::response(['token' => 'bearer-abc'], 201),
            '*' => Http::response(['msgs' => ['CEP inválido']], 400),
        ]);

        $etiqueta = $this->gerar();

        $this->assertSame(CorreiosPrePostagem::STATUS_ERRO, $etiqueta->status);
        $this->assertSame(0, MarketplaceReturn::query()->count());
    }

    public function test_etiqueta_com_erro_corrigida_e_gerada_abre_o_caso(): void
    {
        $etiqueta = CorreiosPrePostagem::create([
            'order_id' => $this->pedido->id, 'devolucao' => true, 'customer_name' => 'Cliente', 'zip' => '13480000', 'street' => 'R', 'number' => '1',
            'neighborhood' => 'C', 'city' => 'Limeira', 'state' => 'SP', 'service_code' => '03298', 'weight_grams' => 400, 'dimension_format' => '2',
            'content_items' => [], 'status' => CorreiosPrePostagem::STATUS_ERRO,
        ]);
        $this->assertSame(0, MarketplaceReturn::query()->count());

        $this->actingAs($this->admin)->put("/admin/correios/{$etiqueta->id}", $this->payload())->assertRedirect();

        $this->assertSame(CorreiosPrePostagem::STATUS_GERADA, $etiqueta->fresh()->status);
        $this->assertSame('OD1BR', MarketplaceReturn::query()->sole()->tracking_number);
    }

    public function test_envio_normal_e_devolucao_sem_pedido_nao_abrem_caso(): void
    {
        $this->gerar(['devolucao' => false]);
        $this->gerar(['order_id' => null]);

        $this->assertSame(CorreiosPrePostagem::STATUS_GERADA, CorreiosPrePostagem::query()->latest('id')->first()->status);
        $this->assertSame(0, MarketplaceReturn::query()->count());
    }

    /** Três etiquetas vivas e depois duas canceladas: um caso, sem reabrir nada. */
    public function test_tres_etiquetas_seguidas_com_duas_canceladas_dao_um_caso_com_o_ultimo_rastreio(): void
    {
        $primeira = $this->gerar();
        $segunda = $this->gerar();
        $this->gerar();
        $this->assertSame('OD3BR', MarketplaceReturn::query()->sole()->tracking_number);

        $this->cancelar($primeira);
        $this->cancelar($segunda);

        $caso = MarketplaceReturn::query()->sole();
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->situacao);
        $this->assertSame('OD3BR', $caso->tracking_number);
        $this->assertSame(1, $this->rascunhos());
        $this->assertSame($caso->devolucao_order_id, Order::query()->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->value('id'));
    }

    /** Gera, cancela, gera, cancela, gera: o mesmo caso reabre e não sobra rascunho órfão. */
    public function test_cancelar_antes_da_proxima_etiqueta_reabre_o_mesmo_caso_com_um_rascunho(): void
    {
        $this->cancelar($this->gerar());
        $this->assertSame(MarketplaceReturn::CANCELADA, MarketplaceReturn::query()->sole()->situacao);
        $this->assertSame(0, $this->rascunhos());

        $this->cancelar($this->gerar());
        $this->assertSame(0, $this->rascunhos());

        $this->gerar();

        $caso = MarketplaceReturn::query()->sole();
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->situacao);
        $this->assertNull($caso->closed_at);
        $this->assertSame('OD3BR', $caso->tracking_number);
        $this->assertSame('preparada', $caso->devolucao_fiscal_status);
        $this->assertSame(1, $this->rascunhos());
        $this->assertSame($caso->devolucao_order_id, Order::query()->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->value('id'));
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_cancelar_a_unica_etiqueta_cancela_o_caso_descarta_o_rascunho_e_nao_emite(): void
    {
        $this->cancelar($this->gerar());

        $caso = MarketplaceReturn::query()->sole();
        $this->assertSame(MarketplaceReturn::CANCELADA, $caso->situacao);
        $this->assertNotNull($caso->closed_at);
        $this->assertNull($caso->devolucao_order_id);
        $this->assertSame('descartada', $caso->devolucao_fiscal_status);
        $this->assertSame(0, $this->rascunhos());
        $this->assertTrue($caso->events()->where('description', 'like', '%OD1BR cancelada%caso cancelado%')->exists());
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_correios_cancelar_tem_o_mesmo_efeito_da_tela(): void
    {
        $etiqueta = $this->gerar();

        $this->artisan('correios:cancelar', ['codigo' => $etiqueta->codigo_objeto])->assertSuccessful();

        $this->assertSame(MarketplaceReturn::CANCELADA, MarketplaceReturn::query()->sole()->situacao);
        $this->assertSame(0, $this->rascunhos());
    }

    public function test_remover_a_unica_etiqueta_cancela_o_caso(): void
    {
        $etiqueta = $this->gerar();

        $this->actingAs($this->admin)->delete("/admin/correios/{$etiqueta->id}")->assertRedirect();

        $caso = MarketplaceReturn::query()->sole();
        $this->assertSame(MarketplaceReturn::CANCELADA, $caso->situacao);
        $this->assertTrue($caso->events()->where('description', 'like', '%OD1BR removida%')->exists());
    }

    public function test_remover_etiqueta_ja_cancelada_nao_mexe_no_caso_da_que_vale(): void
    {
        $velha = $this->gerar();
        $this->gerar();
        $this->cancelar($velha);
        $eventos = MarketplaceReturn::query()->sole()->events()->count();

        $this->actingAs($this->admin)->delete("/admin/correios/{$velha->id}")->assertRedirect();

        $caso = MarketplaceReturn::query()->sole();
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->situacao);
        $this->assertSame('OD2BR', $caso->tracking_number);
        $this->assertSame($eventos, $caso->events()->count());
    }

    public function test_caso_da_api_nao_e_alterado_nem_duplicado(): void
    {
        $api = $this->casoDaApi();
        $antes = $api->fresh()->only(['situacao', 'tracking_number', 'manual', 'external_id', 'closed_at', 'reason_label']);

        $etiqueta = $this->gerar();
        $this->assertSame($antes, MarketplaceReturn::query()->sole()->only(array_keys($antes)));
        $this->assertTrue($api->events()->where('description', 'like', 'Nova etiqueta%OD1BR%')->exists());

        $this->cancelar($etiqueta);
        $this->assertSame($antes, MarketplaceReturn::query()->sole()->only(array_keys($antes)));
    }

    public function test_caso_registrado_a_mao_recebe_o_rastreio_e_nao_e_cancelado_pela_etiqueta(): void
    {
        $this->casoDaApi(['external_id' => 'manual-1', 'manual' => true, 'situacao' => MarketplaceReturn::AGUARDANDO_ENVIO, 'tracking_number' => null]);

        $etiqueta = $this->gerar();
        $this->assertSame('OD1BR', MarketplaceReturn::query()->sole()->tracking_number);

        $this->cancelar($etiqueta);

        $caso = MarketplaceReturn::query()->sole();
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->situacao);
        $this->assertNull($caso->tracking_number);
    }

    public function test_caso_cancelado_pela_equipe_nao_reabre_e_a_nova_etiqueta_abre_outro(): void
    {
        $this->gerar();
        $caso = MarketplaceReturn::query()->sole();
        $this->actingAs($this->admin)->post("/admin/devolucoes/{$caso->id}", ['acao' => 'situacao', 'situacao' => MarketplaceReturn::CANCELADA])->assertRedirect();

        $this->gerar();

        $this->assertSame(MarketplaceReturn::CANCELADA, $caso->fresh()->situacao);
        $this->assertSame(2, MarketplaceReturn::query()->count());
        $this->assertSame(1, $this->rascunhos());
    }

    public function test_produto_ja_recebido_nao_cancela_o_caso_nem_a_nota(): void
    {
        $etiqueta = $this->gerar();
        $caso = MarketplaceReturn::query()->sole();
        $this->actingAs($this->admin)->post("/admin/devolucoes/{$caso->id}", ['acao' => 'receber'])->assertRedirect();

        $this->actingAs($this->admin)->delete("/admin/correios/{$etiqueta->id}")->assertRedirect();

        $caso->refresh();
        $this->assertSame(MarketplaceReturn::ENTREGUE, $caso->situacao);
        $this->assertSame('emitida', $caso->devolucao_fiscal_status);
        $this->assertSame(1, $this->rascunhos());
    }

    public function test_nada_retroativo_etiqueta_antiga_sem_caso_cancelada_nao_abre_caso(): void
    {
        $antiga = CorreiosPrePostagem::create([
            'order_id' => $this->pedido->id, 'devolucao' => true, 'customer_name' => 'Cliente', 'zip' => '13480000', 'street' => 'R', 'number' => '1',
            'neighborhood' => 'C', 'city' => 'Limeira', 'state' => 'SP', 'service_code' => '03298', 'weight_grams' => 400, 'dimension_format' => '2',
            'content_items' => [], 'status' => CorreiosPrePostagem::STATUS_GERADA, 'correios_id' => 'ANTIGA', 'codigo_objeto' => 'OD0BR',
        ]);

        $this->cancelar($antiga);

        $this->assertSame(0, MarketplaceReturn::query()->count());
    }

    public function test_falha_ao_criar_o_caso_nao_derruba_a_etiqueta_e_vai_para_o_log(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logs) {
            $logs[] = $log;
        });
        MarketplaceReturn::creating(fn () => throw new \RuntimeException('banco fora'));

        $resposta = $this->actingAs($this->admin)->post('/admin/correios', $this->payload());

        $etiqueta = CorreiosPrePostagem::query()->sole();
        $resposta->assertRedirect(route('admin.correios.ver', $etiqueta));
        $resposta->assertSessionHas('success');
        $this->assertSame(CorreiosPrePostagem::STATUS_GERADA, $etiqueta->status);
        $this->assertSame('OD1BR', $etiqueta->codigo_objeto);
        $this->assertSame(0, MarketplaceReturn::query()->count());

        $erro = collect($logs)->first(fn (MessageLogged $log) => $log->level === 'error' && str_contains($log->message, 'Devolução PAC'));
        $this->assertNotNull($erro);
        $this->assertSame($etiqueta->id, $erro->context['pre_postagem_id']);
    }
}
