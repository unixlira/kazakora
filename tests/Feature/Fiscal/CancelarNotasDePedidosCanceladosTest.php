<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\MercadoPagoPagamento;
use App\Modules\Fiscal\Jobs\CancelarNotaDoPedidoCanceladoJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use NFePHP\Common\Certificate;
use Tests\TestCase;

/**
 * Pedido cancelado no canal: nota cancelada sozinha na SEFAZ dentro das 24h,
 * nunca fora do prazo (Lira, 2026-10-26: "sem multa").
 */
class CancelarNotasDePedidosCanceladosTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private ?Mockery\MockInterface $webservice = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['app.url' => 'https://kazakora.com']);

        $certificates = Mockery::mock(NFeCertificateService::class);
        $certificates->shouldReceive('load')->andReturn(Mockery::mock(Certificate::class));
        $this->app->instance(NFeCertificateService::class, $certificates);

        $this->webservice = Mockery::mock(NFeWebserviceService::class);
        $this->app->instance(NFeWebserviceService::class, $this->webservice);
    }

    private function sefazResponde(string $cStat): void
    {
        $this->webservice->shouldReceive('cancelarComEvento')->andReturn([
            'request' => '<envEvento/>',
            'response' => "<retEnvEvento xmlns=\"http://www.portalfiscal.inf.br/nfe\"><retEvento><infEvento><cStat>{$cStat}</cStat><xMotivo>ok</xMotivo><nProt>135269999999999</nProt></infEvento></retEvento></retEnvEvento>",
        ]);
    }

    private function nota(string $status = Order::STATUS_CANCELLED, int $horas = 2, string $origem = Order::ORIGIN_SHOPEE): Invoice
    {
        $this->seq++;
        $order = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => $status, 'origin' => $origem,
            'external_order_id' => 'EXT-'.$this->seq, 'shipping_name' => 'Cliente', 'shipping_phone' => '1', 'shipping_zip' => '01001000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP', 'subtotal' => 39.00, 'total' => 39.00,
        ]);

        return Invoice::create([
            'order_id' => $order->id, 'serie' => 2, 'numero' => 1000 + $this->seq, 'status' => Invoice::STATUS_AUTHORIZED,
            'chave_acesso' => '3526106560459000010755002'.str_pad((string) (1000 + $this->seq), 9, '0', STR_PAD_LEFT).'1000030001',
            'protocolo_autorizacao' => '13526000000000'.$this->seq, 'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO,
            'autorizada_em' => now()->subHours($horas), 'valor_total' => 39.00,
        ]);
    }

    public function test_cancela_dentro_das_24h_e_ignora_o_resto(): void
    {
        $this->sefazResponde('135');
        $cancelada = $this->nota();
        $paga = $this->nota(status: Order::STATUS_PAID);
        $foraDoPrazo = $this->nota(horas: 30);
        $naFolga = $this->nota(horas: 0);
        $naFolga->update(['autorizada_em' => now()->subMinutes(24 * 60 - 10)]);

        $this->artisan('nfe:cancelar-pedidos-cancelados')
            ->expectsOutputToContain("#{$cancelada->order_id} ")
            ->assertSuccessful();

        $this->assertSame(Invoice::STATUS_CANCELLED, $cancelada->fresh()->status);
        $this->assertFalse((bool) $cancelada->fresh()->cancelamento_extemporaneo);
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $paga->fresh()->status);
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $foraDoPrazo->fresh()->status);
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $naFolga->fresh()->status);
        $this->webservice->shouldHaveReceived('cancelarComEvento')->once();
    }

    public function test_venda_que_valeu_e_estorno_coberto_pelo_ml_ficam_de_fora(): void
    {
        $valeu = $this->nota();
        config(['nfe.vendas_que_valeram' => [$valeu->order_id]]);
        $coberto = $this->nota(origem: Order::ORIGIN_MERCADO_LIVRE);
        MercadoPagoPagamento::create(['mp_payment_id' => '1770', 'status' => 'refunded', 'status_detalhe' => 'bpp_covered', 'canal' => 'mercado_livre', 'valor' => 39.00, 'order_id' => $coberto->order_id]);

        $this->artisan('nfe:cancelar-pedidos-cancelados')->assertSuccessful();

        $this->webservice->shouldNotHaveReceived('cancelarComEvento');
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $valeu->fresh()->status);
    }

    public function test_simular_nao_cancela(): void
    {
        $nota = $this->nota();

        $this->artisan('nfe:cancelar-pedidos-cancelados', ['--simular' => true])
            ->expectsOutputToContain("#{$nota->order_id} ")
            ->assertSuccessful();

        $this->webservice->shouldNotHaveReceived('cancelarComEvento');
    }

    public function test_falha_na_sefaz_espera_antes_de_tentar_de_novo(): void
    {
        $this->sefazResponde('573');
        $nota = $this->nota();

        $this->artisan('nfe:cancelar-pedidos-cancelados')->assertSuccessful();
        $this->artisan('nfe:cancelar-pedidos-cancelados')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_AUTHORIZED, $nota->fresh()->status);
        $this->webservice->shouldHaveReceived('cancelarComEvento')->once();
    }

    public function test_homolog_nao_cancela_nada(): void
    {
        config(['app.url' => 'https://kazakora.devlira.com.br']);
        $nota = $this->nota();

        $this->artisan('nfe:cancelar-pedidos-cancelados')->assertSuccessful();

        $this->webservice->shouldNotHaveReceived('cancelarComEvento');
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $nota->fresh()->status);
    }

    public function test_webhook_que_cancela_o_pedido_dispara_o_cancelamento_da_nota(): void
    {
        Queue::fake();
        $nota = $this->nota(status: Order::STATUS_PAID);

        app(OrderImportService::class)->syncStatus($nota->order, Order::STATUS_CANCELLED, 'CANCELLED');

        Queue::assertPushed(CancelarNotaDoPedidoCanceladoJob::class, fn ($job) => $job->orderId === $nota->order_id);
    }

    public function test_job_cancela_na_hora_so_a_nota_do_pedido(): void
    {
        $this->sefazResponde('135');
        $nota = $this->nota();
        $outra = $this->nota();

        CancelarNotaDoPedidoCanceladoJob::dispatchSync($nota->order_id);

        $this->assertSame(Invoice::STATUS_CANCELLED, $nota->fresh()->status);
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $outra->fresh()->status);
        $this->assertSame(0, AlertaAdmin::query()->count());
    }

    public function test_job_com_erro_na_sefaz_marca_para_checagem_e_a_rodada_resolve(): void
    {
        $nota = $this->nota();
        $this->webservice->shouldReceive('cancelarComEvento')->once()->andThrow(new \RuntimeException('SEFAZ fora do ar'));

        CancelarNotaDoPedidoCanceladoJob::dispatchSync($nota->order_id);

        $this->assertSame(Invoice::STATUS_AUTHORIZED, $nota->fresh()->status);
        $alerta = AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::NFE_CANCELAMENTO)->sole();
        $this->assertSame("invoice:{$nota->id}", $alerta->chave);
        $this->assertStringContainsString('SEFAZ fora do ar', $alerta->mensagem);
        $this->assertSame("/admin/pedidos/{$nota->order_id}", $alerta->link);

        $this->sefazResponde('135');
        $this->artisan('nfe:cancelar-pedidos-cancelados')->assertSuccessful();

        $this->assertSame(Invoice::STATUS_CANCELLED, $nota->fresh()->status);
        $this->assertNotNull($alerta->fresh()->resolvido_em);
    }

    public function test_nota_do_bling_sem_protocolo_vai_para_checagem(): void
    {
        $nota = $this->nota(origem: Order::ORIGIN_TIKTOK_SHOP);
        $nota->update(['serie' => 3, 'protocolo_autorizacao' => null]);

        CancelarNotaDoPedidoCanceladoJob::dispatchSync($nota->order_id);
        $this->artisan('nfe:cancelar-pedidos-cancelados')->assertSuccessful();

        $this->webservice->shouldNotHaveReceived('cancelarComEvento');
        $alerta = AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::NFE_CANCELAMENTO)->sole();
        $this->assertStringContainsString('Bling', $alerta->mensagem);
    }

    public function test_job_no_homolog_nao_cancela(): void
    {
        config(['app.url' => 'https://kazakora.devlira.com.br']);
        $nota = $this->nota();

        CancelarNotaDoPedidoCanceladoJob::dispatchSync($nota->order_id);

        $this->webservice->shouldNotHaveReceived('cancelarComEvento');
        $this->assertSame(0, AlertaAdmin::query()->count());
    }

    public function test_checagem_das_22h_e_9h_tenta_de_novo_sem_esperar_1h(): void
    {
        $nota = $this->nota();
        $this->webservice->shouldReceive('cancelarComEvento')->once()->andThrow(new \RuntimeException('SEFAZ fora do ar'));
        $this->artisan('nfe:cancelar-pedidos-cancelados')->assertSuccessful();

        $this->sefazResponde('135');
        $this->artisan('nfe:cancelar-pedidos-cancelados')->assertSuccessful();
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $nota->fresh()->status);

        $this->artisan('nfe:cancelar-pedidos-cancelados', ['--checagem' => true])->assertSuccessful();
        $this->assertSame(Invoice::STATUS_CANCELLED, $nota->fresh()->status);
    }
}
