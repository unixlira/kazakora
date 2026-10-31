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
use App\Notifications\DevolucaoPacNotification;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

/**
 * Rastreio da Devolução PAC, nota na chegada e sino (Lira 2026-10-31). Nenhuma
 * nota real sai daqui: GenerateInvoiceJob fica sempre no Bus::fake.
 */
class AcompanharDevolucaoPacTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $pedido;

    private CorreiosPrePostagem $etiqueta;

    /** @var array<string, mixed>|\Throwable */
    private array|\Throwable $rastreio = [];

    private int $consultas = 0;

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

        $produto = Product::factory()->create();
        ProductFiscalData::create(['product_id' => $produto->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => '85044010']);
        $this->pedido = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => Order::STATUS_SHIPPED, 'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => 'ML-1', 'shipping_name' => 'Cliente', 'shipping_phone' => '1', 'shipping_zip' => '01001000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP',
            'shipping_state' => 'RJ', 'subtotal' => 49.99, 'total' => 49.99,
        ]);
        $this->pedido->items()->create(['product_id' => $produto->id, 'product_name' => 'Power Bank', 'product_price' => 49.99, 'quantity' => 1, 'subtotal' => 49.99, 'item_type' => OrderItem::TYPE_PRODUCT]);
        Invoice::create([
            'order_id' => $this->pedido->id, 'serie' => 2, 'numero' => 1506, 'status' => Invoice::STATUS_AUTHORIZED,
            'chave_acesso' => '35260865604590000107550020000015061077037900', 'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO,
        ]);

        $this->etiqueta = $this->etiqueta();

        $rastro = $this->mock(CorreiosRastroService::class);
        $rastro->shouldReceive('consultar')->andReturnUsing(function () {
            $this->consultas++;
            if ($this->rastreio instanceof \Throwable) {
                throw $this->rastreio;
            }

            return $this->rastreio;
        });
        $this->emTransito();
    }

    private function etiqueta(array $extra = []): CorreiosPrePostagem
    {
        return CorreiosPrePostagem::create(array_merge([
            'order_id' => $this->pedido->id, 'devolucao' => true, 'customer_name' => 'Elci', 'zip' => '13480000', 'street' => 'R', 'number' => '1',
            'neighborhood' => 'C', 'city' => 'Limeira', 'state' => 'SP', 'service_code' => '03298', 'weight_grams' => 90, 'dimension_format' => '1',
            'content_items' => [], 'status' => CorreiosPrePostagem::STATUS_GERADA, 'correios_id' => 'PR1', 'codigo_objeto' => 'AP1BR',
        ], $extra));
    }

    private function mapear(): MarketplaceReturn
    {
        app(DevolucaoPacCaso::class)->etiquetaGerada($this->etiqueta);

        return MarketplaceReturn::query()->sole();
    }

    private function emTransito(): void
    {
        $this->rastreio = ['postado' => true, 'postado_em' => now(), 'entregue' => false, 'entregue_em' => null, 'recebedor' => null, 'previsao' => null, 'ultimo' => 'Objeto postado'];
    }

    private function entregue(): void
    {
        $this->rastreio = ['postado' => true, 'postado_em' => now(), 'entregue' => true, 'entregue_em' => Carbon::parse('2026-11-03 10:15', 'America/Sao_Paulo'), 'recebedor' => null, 'previsao' => null, 'ultimo' => 'Objeto entregue ao destinatário'];
    }

    private function rodar(): void
    {
        $this->artisan('devolucoes:acompanhar-pac')->assertSuccessful();
    }

    private function nota(MarketplaceReturn $caso, string $status, array $extra = []): void
    {
        Invoice::create(array_merge([
            'order_id' => $caso->fresh()->devolucao_order_id, 'serie' => 2, 'numero' => 3500, 'status' => $status,
            'chave_acesso' => '35261165604590000107550020000035001077037900', 'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO,
        ], $extra));
    }

    /** @return list<array<string, mixed>> */
    private function sino(): array
    {
        return $this->admin->notifications()
            ->where('type', DevolucaoPacNotification::class)
            ->reorder('created_at')
            ->get()
            ->map(fn ($n) => $n->data)
            ->values()
            ->all();
    }

    public function test_sem_postagem_nada_muda(): void
    {
        $caso = $this->mapear();
        $this->rastreio = ['postado' => false, 'postado_em' => null, 'entregue' => false, 'entregue_em' => null, 'recebedor' => null, 'previsao' => null, 'ultimo' => 'Etiqueta emitida'];

        $this->rodar();

        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->fresh()->situacao);
        $this->assertSame([], $this->sino());
    }

    public function test_postada_vira_a_caminho_sem_nota_e_sem_sino(): void
    {
        $caso = $this->mapear();

        $this->rodar();

        $this->assertSame(MarketplaceReturn::EM_TRANSITO, $caso->fresh()->situacao);
        $this->assertSame('preparada', $caso->fresh()->devolucao_fiscal_status);
        $this->assertSame([], $this->sino());
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
    }

    public function test_entregue_emite_a_nota_uma_vez_e_avisa_no_sino_uma_vez(): void
    {
        $caso = $this->mapear();
        $this->entregue();

        $this->rodar();
        $this->rodar();

        $caso->refresh();
        $this->assertSame(MarketplaceReturn::ENTREGUE, $caso->situacao);
        $this->assertSame('2026-11-03 10:15', $caso->delivered_at->timezone('America/Sao_Paulo')->format('Y-m-d H:i'));
        $this->assertSame('emitida', $caso->devolucao_fiscal_status);
        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
        $this->assertSame(1, $this->consultas);

        $sino = $this->sino();
        $this->assertCount(1, $sino);
        $this->assertStringContainsString("pedido #{$this->pedido->id}", $sino[0]['message']);
        $this->assertStringContainsString('AP1BR', $sino[0]['body']);
        $this->assertStringContainsString('03/11/2026 10:15', $sino[0]['body']);
        $this->assertSame("/admin/devolucoes?caso={$caso->id}", $sino[0]['link']);
    }

    public function test_nota_autorizada_avisa_o_numero_uma_vez_e_para_de_consultar(): void
    {
        $caso = $this->mapear();
        $this->entregue();
        $this->rodar();
        $this->nota($caso, Invoice::STATUS_AUTHORIZED);

        $this->rodar();
        $this->rodar();

        $sino = $this->sino();
        $this->assertCount(2, $sino);
        $this->assertStringContainsString('2-3500 autorizada', $sino[1]['message']);
        $this->assertSame('sucesso', $sino[1]['nivel']);
        $this->assertSame(1, $this->consultas);
    }

    public function test_nota_rejeitada_avisa_com_o_motivo(): void
    {
        $caso = $this->mapear();
        $this->entregue();
        $this->rodar();
        $this->nota($caso, Invoice::STATUS_REJECTED, ['motivo_rejeicao' => 'Duplicidade de NF-e']);

        $this->rodar();

        $sino = $this->sino();
        $this->assertSame('erro', $sino[1]['nivel']);
        $this->assertStringContainsString('Duplicidade de NF-e', $sino[1]['body']);
    }

    public function test_nota_sem_resposta_so_avisa_depois_de_duas_horas(): void
    {
        $this->mapear();
        $this->entregue();
        $this->rodar();

        $this->rodar();
        $this->assertCount(1, $this->sino());

        $this->travel(121)->minutes();
        $this->rodar();

        $sino = $this->sino();
        $this->assertCount(2, $sino);
        $this->assertSame('erro', $sino[1]['nivel']);
        $this->assertStringContainsString('sem resposta', $sino[1]['message']);
    }

    public function test_caso_da_api_so_recebe_o_aviso_e_nao_muda_de_situacao(): void
    {
        $caso = MarketplaceReturn::create([
            'channel' => Order::ORIGIN_MERCADO_LIVRE, 'external_id' => 'CLAIM-1', 'order_id' => $this->pedido->id, 'external_order_id' => 'ML-1',
            'kind' => MarketplaceReturn::KIND_DEVOLUCAO, 'reason_label' => 'Defeito', 'situacao' => MarketplaceReturn::EM_TRANSITO, 'tracking_number' => 'API123', 'manual' => false,
        ]);
        $this->entregue();

        $this->rodar();

        $this->assertSame(MarketplaceReturn::EM_TRANSITO, $caso->fresh()->situacao);
        Bus::assertNotDispatched(GenerateInvoiceJob::class);
        $this->assertStringContainsString('segue a plataforma', $this->sino()[0]['body']);
    }

    public function test_produto_ja_recebido_na_tela_nao_emite_de_novo(): void
    {
        $caso = $this->mapear();
        $this->actingAs($this->admin)->post("/admin/devolucoes/{$caso->id}", ['acao' => 'receber'])->assertRedirect();
        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
        $this->entregue();

        $this->rodar();

        Bus::assertDispatchedTimes(GenerateInvoiceJob::class, 1);
        $this->assertCount(1, $this->sino());
    }

    public function test_etiqueta_sem_caso_cancelada_ou_de_envio_fica_de_fora(): void
    {
        $this->entregue();
        $this->rodar();
        $this->assertSame(0, $this->consultas);

        $caso = $this->mapear();
        $this->etiqueta->update(['status' => CorreiosPrePostagem::STATUS_CANCELADA]);
        $this->etiqueta(['devolucao' => false, 'codigo_objeto' => 'AP2BR', 'correios_id' => 'PR2']);

        $this->rodar();

        $this->assertSame(0, $this->consultas);
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->fresh()->situacao);
    }

    public function test_falha_no_rastreio_nao_derruba_e_tenta_de_novo(): void
    {
        $caso = $this->mapear();
        $this->rastreio = new RuntimeException('Correios fora');

        $this->rodar();
        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->fresh()->situacao);

        $this->entregue();
        $this->rodar();
        $this->assertSame(MarketplaceReturn::ENTREGUE, $caso->fresh()->situacao);
    }

    public function test_simular_nao_grava_nem_avisa(): void
    {
        $caso = $this->mapear();
        $this->entregue();

        $this->artisan('devolucoes:acompanhar-pac', ['--simular' => true])->assertSuccessful();

        $this->assertSame(MarketplaceReturn::AGUARDANDO_ENVIO, $caso->fresh()->situacao);
        $this->assertSame([], $this->sino());
    }
}
