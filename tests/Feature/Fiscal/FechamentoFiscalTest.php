<?php

namespace Tests\Feature\Fiscal;

use App\Mail\FechamentoFiscalMail;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\NumeracaoOcorrencia;
use App\Modules\Fiscal\Services\FechamentoFiscalService;
use App\Modules\Fiscal\Services\InutilizacaoService;
use App\Modules\Fiscal\Services\UfespService;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use NFePHP\Common\Certificate;
use RuntimeException;
use Tests\TestCase;

/**
 * Fechamento fiscal do mês pro contador (2026-10-09): resumo por série,
 * canceladas no prazo e fora dele com multa pela UFESP do ano, números sem
 * nota pra inutilizar, duplicidades, e-mail todo dia 1º. Mais a linha do
 * tempo do pedido sem eventos repetidos.
 */
class FechamentoFiscalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['nfe.serie' => 2, 'nfe.ambiente' => 'producao', 'nfe.numero_inicial' => 1000, 'nfe.fechamento_email' => 'dono@example.com']);
        Carbon::setTestNow('2026-10-01 07:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pedido(string $status = Order::STATUS_COMPLETED, ?string $operacao = null): Order
    {
        return Order::query()->create(array_filter([
            'status' => $status,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua A',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'shipping_cost' => 0,
            'total' => 100,
            'fiscal_operation_type' => $operacao,
        ]));
    }

    private function nota(int $numero, string $status = Invoice::STATUS_AUTHORIZED, array $extra = [], ?Order $pedido = null): Invoice
    {
        return Invoice::query()->create([
            'order_id' => ($pedido ?? $this->pedido())->id,
            'status' => $status,
            'ambiente' => 'producao',
            'serie' => 2,
            'numero' => $numero,
            'valor_total' => 100,
            'chave_acesso' => '352609656045900001075500200000'.str_pad((string) $numero, 4, '0', STR_PAD_LEFT).'1'.'12345678'.'1',
            'autorizada_em' => '2026-09-10 10:00:00',
            'xml_path' => "invoices/x/nfe-{$numero}.xml",
            ...$extra,
        ]);
    }

    public function test_the_ufesp_of_the_year_comes_from_the_table_and_drives_the_fine(): void
    {
        $this->assertSame(38.42, app(UfespService::class)->valor(2026));
        $this->assertSame(37.02, app(UfespService::class)->valor(2025));
        // Ano sem valor ainda: usa o mais recente.
        $this->assertSame(38.42, app(UfespService::class)->valor(2027));

        // 6 × 38,42 = 230,52 (mínimo) numa nota de R$ 100.
        $this->assertSame(230.52, $this->nota(1001)->multaCancelamentoExtemporaneo());
    }

    public function test_ufesp_update_only_saves_what_the_official_page_confirms(): void
    {
        Http::fake([
            'yahii.com.br/*' => Http::response('<table><tr><td>de 01/01/2027 A 31/12/2027</td><td>39,80</td><td>Comunicado DICAR-90/26 , de 17-12-2026</td></tr></table>'),
            'legislacao.fazenda.sp.gov.br/Paginas/Comunicado-DICAR-90-de-2026.aspx' => Http::response('<p>para o período de 1º de janeiro a 31 de dezembro de 2027, será de R$&nbsp;39,80 (trinta e nove reais)</p>'),
        ]);

        $r = app(UfespService::class)->atualizar(2027);

        $this->assertSame(39.8, $r['valor']);
        $this->assertSame('Comunicado DICAR-90/26, de 17-12-2026', $r['base_legal']);
        $this->assertSame(39.8, app(UfespService::class)->valor(2027));
    }

    public function test_ufesp_update_refuses_a_value_the_official_page_does_not_show(): void
    {
        Http::fake([
            'yahii.com.br/*' => Http::response('de 01/01/2027 A 31/12/2027 99,99 Comunicado DICAR-90/26 , de 17-12-2026'),
            'legislacao.fazenda.sp.gov.br/*' => Http::response('2027 será de R$ 39,80'),
        ]);

        $this->expectException(RuntimeException::class);

        try {
            app(UfespService::class)->atualizar(2027);
        } finally {
            $this->assertDatabaseMissing('ufesp_valores', ['ano' => 2027]);
        }
    }

    public function test_month_report_separates_cancellations_inside_and_outside_the_window(): void
    {
        $this->nota(1001);
        $this->nota(1002, Invoice::STATUS_CANCELLED, ['cancelada_em' => '2026-09-10 20:00:00']);
        $this->nota(1003, Invoice::STATUS_CANCELLED, ['cancelada_em' => '2026-09-14 10:00:00', 'cancelamento_extemporaneo' => true]);
        $this->nota(1004, Invoice::STATUS_AUTHORIZED, [], $this->pedido(operacao: 'sales_return'));
        $this->nota(900, Invoice::STATUS_AUTHORIZED, ['serie' => 3, 'chave_acesso' => null, 'xml_path' => null]);
        // Fora do mês: não entra.
        $this->nota(1005, Invoice::STATUS_AUTHORIZED, ['autorizada_em' => '2026-08-31 23:00:00']);

        $r = app(FechamentoFiscalService::class)->gerar(Carbon::parse('2026-09-01'));

        $this->assertSame(3, $r['totais']['autorizadas']);
        $this->assertSame(2, $r['totais']['canceladas']);
        $this->assertSame(1, $r['totais']['canceladas_fora_do_prazo']);
        $this->assertSame(230.52, $r['totais']['multa_estimada']);
        $this->assertSame(1, $r['totais']['devolucoes']);
        $this->assertSame(1, $r['totais']['sem_xml']);
        $this->assertSame([2, 3], array_column($r['series'], 'serie'));

        $canceladas = collect($r['canceladas'])->keyBy('numero');
        $this->assertFalse($canceladas[1002]['fora_do_prazo']);
        $this->assertSame(10, $canceladas[1002]['horas_ate_cancelar']);
        $this->assertTrue($canceladas[1003]['fora_do_prazo']);
        $this->assertSame(96, $canceladas[1003]['horas_ate_cancelar']);
    }

    public function test_gaps_list_missing_numbers_and_rejected_notes_of_cancelled_orders_only(): void
    {
        $this->nota(1001);
        $this->nota(1002);
        // 1003 e 1004: sem nada no sistema.
        $this->nota(1005);
        $rejeitadaAbandonada = $this->nota(1006, Invoice::STATUS_REJECTED, ['autorizada_em' => null], $this->pedido(Order::STATUS_CANCELLED));
        $rejeitadaAbandonada->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        // Rejeitada de pedido ativo: número reservado pro reenvio, não é buraco.
        $this->nota(1007, Invoice::STATUS_REJECTED, ['autorizada_em' => null]);
        // 1008 inutilizado e 1009 consumido por outra chave: não são buraco.
        NumeracaoOcorrencia::query()->create(['tipo' => 'inutilizacao', 'ambiente' => 'producao', 'serie' => 2, 'numero_inicial' => 1008, 'numero_final' => 1008, 'resolvido_em' => now()]);
        NumeracaoOcorrencia::query()->create(['tipo' => 'duplicidade', 'ambiente' => 'producao', 'serie' => 2, 'numero_inicial' => 1009, 'numero_final' => 1009]);
        $this->nota(1010);
        // Série de outro emissor não conta.
        $this->nota(5000, Invoice::STATUS_AUTHORIZED, ['serie' => 3, 'chave_acesso' => null]);

        $buracos = app(FechamentoFiscalService::class)->buracos(2, 'producao');

        $this->assertSame([
            ['inicio' => 1003, 'fim' => 1004, 'motivo' => 'sem nota no sistema', 'quantidade' => 2],
            ['inicio' => 1006, 'fim' => 1006, 'motivo' => 'nota rejeitada de pedido cancelado', 'quantidade' => 1],
        ], $buracos);
    }

    public function test_inutilization_only_accepts_a_real_gap_and_records_the_protocol(): void
    {
        $this->nota(1001);
        $this->nota(1004);

        $certificado = Mockery::mock(NFeCertificateService::class);
        $certificado->shouldReceive('load')->andReturn(Mockery::mock(Certificate::class));
        $this->app->instance(NFeCertificateService::class, $certificado);

        $ws = Mockery::mock(NFeWebserviceService::class);
        $ws->shouldReceive('inutilizar')->once()->with(2, 1002, 1003, 'Numeracao nao utilizada por falha', Mockery::any())->andReturn([
            'request' => '<inutNFe/>',
            'response' => '<retInutNFe xmlns="http://www.portalfiscal.inf.br/nfe"><infInut><cStat>102</cStat><xMotivo>Inutilizacao de numero homologado</xMotivo><nProt>135260000000001</nProt></infInut></retInutNFe>',
        ]);
        $this->app->instance(NFeWebserviceService::class, $ws);

        $servico = app(InutilizacaoService::class);

        // 1001 tem nota autorizada: recusa antes de falar com a SEFAZ.
        try {
            $servico->inutilizar(1001, 1002, 'Numeracao nao utilizada por falha', null);
            $this->fail('Deveria recusar faixa com número usado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('não estão todos livres', $e->getMessage());
        }

        $o = $servico->inutilizar(1002, 1003, 'Numeracao nao utilizada por falha', null);

        $this->assertSame('135260000000001', $o->protocolo);
        Storage::disk('local')->assertExists($o->xml_path);
        $this->assertSame([], app(FechamentoFiscalService::class)->buracos(2, 'producao'));
    }

    public function test_inutilize_endpoint_requires_typed_confirmation(): void
    {
        $this->nota(1001);
        $this->nota(1003);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->mock(NFeWebserviceService::class)->shouldNotReceive('inutilizar');

        $this->actingAs($admin)
            ->post('/admin/notas-fiscais/fechamento/inutilizar', ['inicio' => 1002, 'fim' => 1002, 'justificativa' => 'Numeracao nao utilizada por falha', 'confirmacao' => 'sim'])
            ->assertSessionHasErrors('confirmacao');
    }

    public function test_monthly_email_goes_once_with_csv_and_zip(): void
    {
        Mail::fake();
        $this->nota(1001);
        $this->nota(1002, Invoice::STATUS_CANCELLED, ['cancelada_em' => '2026-09-10 12:00:00']);
        Storage::disk('local')->put('invoices/x/nfe-1001.xml', '<nfeProc/>');

        $this->artisan('fiscal:fechamento-mensal')->assertSuccessful();
        $this->artisan('fiscal:fechamento-mensal')->assertSuccessful();

        Mail::assertSent(FechamentoFiscalMail::class, 1);
        Mail::assertSent(FechamentoFiscalMail::class, function (FechamentoFiscalMail $mail) {
            $this->assertSame('2026-09', $mail->relatorio['mes']);
            $this->assertStringContainsString('1002', $mail->csv);
            $this->assertNotNull($mail->zipPath);

            $zip = new \ZipArchive;
            $zip->open(Storage::disk('local')->path($mail->zipPath));
            $this->assertNotFalse($zip->locateName('nfe/002-000001001.xml'));
            $zip->close();

            return $mail->hasTo('dono@example.com');
        });
    }

    public function test_closing_page_renders_with_open_duplicates(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pedido = $this->pedido();
        NumeracaoOcorrencia::query()->create(['tipo' => 'duplicidade', 'ambiente' => 'producao', 'serie' => 2, 'numero_inicial' => 2037, 'numero_final' => 2037, 'order_id' => $pedido->id]);

        $this->actingAs($admin)->get('/admin/notas-fiscais/fechamento?mes=2026-09')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Invoices/Fechamento', false)
                ->where('relatorio.mes', '2026-09')
                ->where('duplicidadesAbertas.0.numero', 2037));

        $ocorrencia = NumeracaoOcorrencia::query()->first();
        $this->actingAs($admin)->post("/admin/notas-fiscais/fechamento/duplicidades/{$ocorrencia->id}/conferida")->assertRedirect();
        $this->assertNotNull($ocorrencia->fresh()->resolvido_em);
    }

    public function test_timeline_counts_repeated_events_instead_of_duplicating_them(): void
    {
        $pedido = $this->pedido();
        $timeline = app(OrderFulfillmentTimeline::class);

        $timeline->record($pedido, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, 'success', 'Webhook reentregue (tiktok_shop), status=paid');
        $timeline->record($pedido, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, 'success', 'Webhook reentregue (tiktok_shop), status=paid');
        $timeline->record($pedido, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, 'success', 'Webhook reentregue (tiktok_shop), status=paid');
        // Outro resultado na mesma etapa = linha nova.
        $timeline->record($pedido, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, 'success', 'Webhook reentregue (tiktok_shop), status=shipped');

        $eventos = $pedido->fulfillmentEvents()->get();
        $this->assertCount(2, $eventos);
        $this->assertSame(3, $eventos[0]->repeticoes);
        $this->assertSame(1, $eventos[1]->repeticoes);
    }

    public function test_old_repeated_events_are_compacted_by_the_command(): void
    {
        $pedido = $this->pedido();

        foreach (range(1, 5) as $i) {
            OrderFulfillmentEvent::query()->create(['order_id' => $pedido->id, 'step' => 'shipping_confirmed', 'status' => 'failed', 'message' => 'Pedido não encontrado no Bling.']);
        }
        OrderFulfillmentEvent::query()->create(['order_id' => $pedido->id, 'step' => 'label_generated', 'status' => 'success', 'message' => 'Etiqueta ok']);
        OrderFulfillmentEvent::query()->create(['order_id' => $pedido->id, 'step' => 'shipping_confirmed', 'status' => 'failed', 'message' => 'Pedido não encontrado no Bling.']);

        // Sem --executar só conta.
        $this->artisan('pedidos:compactar-linha-do-tempo')->expectsOutputToContain('5 em 1 pedido')->assertSuccessful();
        $this->assertSame(7, OrderFulfillmentEvent::query()->count());

        $this->artisan('pedidos:compactar-linha-do-tempo --executar')->assertSuccessful();

        $eventos = OrderFulfillmentEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame(6, $eventos[0]->repeticoes);
        $this->assertSame('label_generated', $eventos[1]->step);
    }
}
