<?php

namespace Tests\Feature\Fiscal;

use App\Models\Setting;
use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\NumeracaoOcorrencia;
use App\Modules\Fiscal\Services\ConciliacaoFiscalService;
use App\Modules\Fiscal\Services\DevolucaoFiscalAutomatica;
use App\Modules\Fiscal\Services\FechamentoFiscalService;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Modules\Marketplace\Support\MercadoLivreFull;
use App\Services\Bling\BlingInvoiceImporter;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use NFePHP\Common\Certificate;
use Tests\TestCase;

/**
 * Conciliação fiscal autônoma (pedido do Lira em 12/11/2026: "se foi
 * cancelado ou devolvido o sistema tem que fazer automático, com alerta"):
 * fiscal:conciliar só lê e alerta; --executar inutiliza na SEFAZ o número
 * sem nota há mais de 3 dias; nota parada de pedido cancelado é conferida
 * na SEFAZ; e a passada diária do Bling olha 60 dias.
 */
class ConciliacaoFiscalTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Mockery\MockInterface $webservice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-11-12 10:00:00');
        config([
            'app.url' => 'https://kazakora.com',
            'nfe.serie' => 2, 'nfe.ambiente' => 'producao', 'nfe.numero_inicial' => 1000,
        ]);

        $certificado = Mockery::mock(NFeCertificateService::class);
        $certificado->shouldReceive('load')->andReturn(Mockery::mock(Certificate::class));
        $this->app->instance(NFeCertificateService::class, $certificado);

        $this->webservice = Mockery::mock(NFeWebserviceService::class);
        $this->app->instance(NFeWebserviceService::class, $this->webservice);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pedido(string $status = Order::STATUS_PAID, string $origem = Order::ORIGIN_SHOPEE, int $horas = 5): Order
    {
        $this->seq++;
        $pedido = Order::query()->create([
            'status' => $status, 'origin' => $origem, 'external_order_id' => 'EXT-'.$this->seq,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000', 'shipping_street' => 'Rua A',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);
        $pedido->forceFill(['created_at' => now()->subHours($horas)])->saveQuietly();

        return $pedido;
    }

    private function nota(int $numero, ?Order $pedido, string $status = Invoice::STATUS_AUTHORIZED, int $diasAtras = 5, array $extra = []): Invoice
    {
        $nota = Invoice::query()->create([
            'order_id' => $pedido?->id, 'status' => $status, 'ambiente' => 'producao', 'serie' => 2, 'numero' => $numero, 'valor_total' => 100,
            'chave_acesso' => '3526106560459000010755002'.str_pad((string) $numero, 9, '0', STR_PAD_LEFT).'1000030001',
            'protocolo_autorizacao' => $status === Invoice::STATUS_AUTHORIZED ? '135260000000'.$numero : null,
            'autorizada_em' => $status === Invoice::STATUS_AUTHORIZED ? now()->subDays($diasAtras) : null,
            ...$extra,
        ]);
        $nota->forceFill(['created_at' => now()->subDays($diasAtras), 'updated_at' => now()->subDays($diasAtras)])->saveQuietly();

        return $nota;
    }

    private function consultaResponde(string $cStat, ?string $nProt = null): void
    {
        $prot = $nProt ? "<protNFe><infProt><cStat>100</cStat><nProt>{$nProt}</nProt><dhRecbto>2026-11-01T10:00:00-03:00</dhRecbto></infProt></protNFe>" : '';
        $this->webservice->shouldReceive('consultarChave')->andReturn("<retConsSitNFe xmlns=\"http://www.portalfiscal.inf.br/nfe\"><cStat>{$cStat}</cStat><xMotivo>situacao</xMotivo>{$prot}</retConsSitNFe>");
    }

    private function inutilizacaoResponde(string $cStat): void
    {
        $this->webservice->shouldReceive('inutilizar')->andReturn([
            'request' => '<inutNFe/>',
            'response' => "<retInutNFe xmlns=\"http://www.portalfiscal.inf.br/nfe\"><infInut><cStat>{$cStat}</cStat><xMotivo>resposta</xMotivo><nProt>135260000000999</nProt></infInut></retInutNFe>",
        ]);
    }

    private function alerta(string $chave): ?AlertaAdmin
    {
        return AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::FISCAL_CONCILIACAO)->where('chave', $chave)->first();
    }

    public function test_conferencia_so_le_e_abre_um_alerta_por_grupo(): void
    {
        $semNota = $this->pedido();
        $this->pedido(horas: 1); // Recente: ainda dentro das 2h.
        $full = $this->pedido(origem: Order::ORIGIN_MERCADO_LIVRE);
        ChannelShipment::create(['order_id' => $full->id, 'channel' => 'mercado_livre', 'status' => ChannelShipment::STATUS_CONFIRMED, 'shipping_method' => MercadoLivreFull::LOGISTIC_TYPE]);

        $cancelado = $this->pedido(Order::STATUS_CANCELLED);
        $cancelado->items()->create(['product_name' => 'Webcam', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100]);
        $viva = $this->nota(1001, $cancelado, diasAtras: 2);

        NumeracaoOcorrencia::query()->create(['tipo' => 'duplicidade', 'ambiente' => 'producao', 'serie' => 2, 'numero_inicial' => 1002, 'numero_final' => 1002]);
        $semPedido = $this->nota(1003, null);
        MarketplaceReturn::withoutEvents(fn () => MarketplaceReturn::query()->create([
            'channel' => 'shopee', 'external_id' => 'R1', 'order_id' => $cancelado->id, 'situacao' => 'aberta',
            'devolucao_fiscal_status' => DevolucaoFiscalAutomatica::PREPARADA, 'opened_at' => now()->subDays(20),
        ]));

        $this->webservice->shouldNotReceive('inutilizar');
        $this->webservice->shouldNotReceive('cancelarComEvento');

        $this->artisan('fiscal:conciliar')->assertSuccessful();

        $this->assertStringContainsString("#{$semNota->id} shopee (sem nota)", $this->alerta('vendas_sem_nota')->mensagem);
        $this->assertStringNotContainsString("#{$full->id} ", $this->alerta('vendas_sem_nota')->mensagem);
        $this->assertStringStartsWith('1 ', $this->alerta('vendas_sem_nota')->titulo);
        $this->assertStringContainsString("pedido #{$cancelado->id} (devolução às 08:20)", $this->alerta('canceladas_com_nota')->mensagem);
        $this->assertStringContainsString('nº 1002', $this->alerta('ocorrencias_abertas')->mensagem);
        $this->assertStringContainsString("invoice {$semPedido->id}", $this->alerta('notas_sem_pedido')->mensagem);
        $this->assertNotNull($this->alerta('devolucoes_paradas'));

        $resumo = json_decode(Setting::get(ConciliacaoFiscalService::RESUMO), true);
        $this->assertSame(1, $resumo['totais']['vendas_sem_nota']);
        $this->assertSame([$viva->id], $resumo['ids']['canceladas_com_nota']);

        // Resolveu: o alerta do grupo fecha sozinho na próxima conferência.
        $this->nota(1004, $semNota, diasAtras: 0);
        $this->artisan('fiscal:conciliar')->assertSuccessful();

        $this->assertNull($this->alerta('vendas_sem_nota'));
        $this->assertNotNull($this->alerta('canceladas_com_nota'));
    }

    public function test_inutiliza_sozinho_o_numero_sem_nota_ha_mais_de_3_dias(): void
    {
        $this->nota(1001, $this->pedido());
        $this->nota(1003, $this->pedido());
        // 1004 sem nota, mas a 1005 é de hoje: ainda recente.
        $this->nota(1005, $this->pedido(), diasAtras: 0);
        $this->inutilizacaoResponde('102');

        $this->artisan('fiscal:conciliar --executar')->assertSuccessful();

        $this->webservice->shouldHaveReceived('inutilizar')->with(2, 1002, 1002, ConciliacaoFiscalService::JUSTIFICATIVA, Mockery::any())->once();
        $this->assertDatabaseHas('nfe_numeracao_ocorrencias', ['tipo' => 'inutilizacao', 'numero_inicial' => 1002, 'numero_final' => 1002, 'protocolo' => '135260000000999']);
        $this->assertSame(
            [['inicio' => 1004, 'fim' => 1004, 'motivo' => 'sem nota no sistema', 'quantidade' => 1]],
            app(FechamentoFiscalService::class)->buracos(2, 'producao'),
        );
    }

    public function test_recusa_da_sefaz_vira_alerta_e_nao_tenta_de_novo(): void
    {
        $this->nota(1001, $this->pedido());
        $this->nota(1003, $this->pedido());
        $this->inutilizacaoResponde('241');

        $this->artisan('fiscal:conciliar --executar')->assertSuccessful();
        $this->artisan('fiscal:conciliar --executar')->assertSuccessful();

        $this->webservice->shouldHaveReceived('inutilizar')->once();
        $this->assertStringContainsString('241', $this->alerta('inutilizacao:2:1002-1002')->mensagem);
        $this->assertSame(0, NumeracaoOcorrencia::query()->count());
    }

    public function test_fora_da_producao_nao_inutiliza(): void
    {
        $this->nota(1001, $this->pedido());
        $this->nota(1003, $this->pedido());
        $this->webservice->shouldNotReceive('inutilizar');

        config(['app.url' => 'https://kazakora.devlira.com.br']);
        $this->artisan('fiscal:conciliar --executar')->assertSuccessful();

        config(['app.url' => 'https://kazakora.com', 'nfe.ambiente' => 'homologacao']);
        $this->artisan('fiscal:conciliar --executar')->assertSuccessful();

        $this->assertSame(0, NumeracaoOcorrencia::query()->count());
    }

    /** Caso real: invoice 2261, nº 2981, assinada desde 11/10 de pedido cancelado. */
    public function test_nota_parada_de_pedido_cancelado_que_a_sefaz_nao_conhece_vai_para_inutilizacao(): void
    {
        $this->nota(1001, $this->pedido());
        $parada = $this->nota(1002, $this->pedido(Order::STATUS_CANCELLED), Invoice::STATUS_SIGNED, diasAtras: 30);
        $this->nota(1003, $this->pedido());

        $this->assertSame(FechamentoFiscalService::MOTIVO_PARADA, app(FechamentoFiscalService::class)->buracos(2, 'producao')[0]['motivo']);

        $this->consultaResponde('217');
        $this->artisan('nfe:retry-stuck --so-conferir')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_REJECTED, $parada->fresh()->status);
        $this->assertStringContainsString('inutilização', $this->alerta("parada:{$parada->id}")->mensagem);

        // Quatro dias depois a inutilização automática confere de novo e inutiliza.
        Carbon::setTestNow(now()->addDays(4));
        $this->inutilizacaoResponde('102');
        $this->artisan('fiscal:conciliar --executar')->assertSuccessful();

        $this->webservice->shouldHaveReceived('inutilizar')->with(2, 1002, 1002, Mockery::any(), Mockery::any())->once();
        $this->assertNull($this->alerta("parada:{$parada->id}"));
    }

    public function test_nota_local_que_existe_na_sefaz_nao_e_inutilizada(): void
    {
        $this->nota(1001, $this->pedido());
        $parada = $this->nota(1002, $this->pedido(Order::STATUS_CANCELLED), Invoice::STATUS_PENDING, diasAtras: 10);
        $this->nota(1003, $this->pedido());
        $this->consultaResponde('100', '135260000001002');
        $this->webservice->shouldNotReceive('inutilizar');

        $this->artisan('fiscal:conciliar --executar')->assertSuccessful();

        $this->assertStringContainsString("invoice {$parada->id}", $this->alerta('inutilizacao:2:1002-1002')->mensagem);
        $this->assertSame(0, NumeracaoOcorrencia::query()->count());
    }

    public function test_passada_diaria_do_bling_olha_60_dias_so_do_que_nao_esta_autorizado(): void
    {
        config(['services.bling.invoice_issuer_channels' => [Order::ORIGIN_TIKTOK_SHOP]]);
        $antigoPendente = $this->pedido(origem: Order::ORIGIN_TIKTOK_SHOP, horas: 24 * 20);
        $this->nota(1001, $antigoPendente, Invoice::STATUS_PENDING, extra: ['serie' => 3, 'chave_acesso' => null]);
        $antigoSemNota = $this->pedido(origem: Order::ORIGIN_TIKTOK_SHOP, horas: 24 * 30);
        // Autorizada sem XML: fica com a passada de 5 min, não com a diária.
        $autorizado = $this->pedido(origem: Order::ORIGIN_TIKTOK_SHOP, horas: 24 * 20);
        $this->nota(1002, $autorizado);
        $velhoDemais = $this->pedido(origem: Order::ORIGIN_TIKTOK_SHOP, horas: 24 * 70);

        $vistos = [];
        $importador = Mockery::mock(BlingInvoiceImporter::class);
        $importador->shouldReceive('syncForOrder')->andReturnUsing(function (Order $pedido) use (&$vistos) {
            $vistos[] = $pedido->id;

            return null;
        });
        $this->app->instance(BlingInvoiceImporter::class, $importador);

        $this->artisan('invoices:sync-bling')->assertSuccessful();
        $this->assertSame([], $vistos);

        $this->artisan('invoices:sync-bling --dias=60 --so-nao-autorizadas')->assertSuccessful();
        sort($vistos);
        $this->assertSame([$antigoPendente->id, $antigoSemNota->id], $vistos);
        $this->assertNotContains($velhoDemais->id, $vistos);
    }
}
