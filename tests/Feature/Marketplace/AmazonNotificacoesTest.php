<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\ProcessarNotificacaoAmazon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\AmazonNotificacao;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\Amazon\AmazonFilaNotificacoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Aviso de pedido da Amazon em tempo real (ORDER_CHANGE pela fila SQS).
 * Payload no formato da doc da Notifications API (notification-type-values).
 */
class AmazonNotificacoesTest extends TestCase
{
    use RefreshDatabase;

    private const NUMERO = '701-2931770-8321845';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amazon.sp_api_base_url' => 'https://sellingpartnerapi.test',
            'services.amazon.notificacoes.sqs_arn' => 'arn:aws:sqs:us-east-1:123456789012:kazakora-amazon-notificacoes',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aviso(string $status, string $id = 'd0e9e693-0001'): array
    {
        return [
            'NotificationVersion' => '1.0',
            'NotificationType' => 'ORDER_CHANGE',
            'PayloadVersion' => '1.0',
            'EventTime' => '2026-10-16T19:42:04.284Z',
            'Payload' => ['OrderChangeNotification' => [
                'NotificationLevel' => 'OrderLevel',
                'SellerId' => 'AHYVX2RD13B6J',
                'AmazonOrderId' => self::NUMERO,
                'OrderChangeType' => 'OrderStatusChange',
                'OrderChangeTrigger' => ['TimeOfOrderChange' => '2026-10-16T19:42:04.284Z', 'ChangeReason' => 'Order status changed'],
                'Summary' => ['MarketplaceId' => 'A2Q3Y263D00KWC', 'OrderStatus' => $status, 'FulfillmentType' => 'MFN'],
            ]],
            'NotificationMetadata' => [
                'ApplicationId' => 'amzn1.sp.solution.teste',
                'SubscriptionId' => 'sub-1',
                'PublishTime' => '2026-10-16T19:42:05.000Z',
                'NotificationId' => $id,
            ],
        ];
    }

    private function filaFalsa(array $corpos): object
    {
        $fila = new class($corpos) extends AmazonFilaNotificacoes
        {
            public array $apagadas = [];

            public function __construct(private array $corpos) {}

            public function configurada(): bool
            {
                return true;
            }

            public function receber(int $espera = 20): array
            {
                $saida = [];

                foreach ($this->corpos as $i => $corpo) {
                    $saida[] = ['receipt' => "r{$i}", 'body' => $corpo];
                }

                $this->corpos = [];

                return $saida;
            }

            public function apagar(string $receipt): void
            {
                $this->apagadas[] = $receipt;
            }
        };

        $this->app->instance(AmazonFilaNotificacoes::class, $fila);

        return $fila;
    }

    private function pedidoPago(): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => self::NUMERO,
            'shipping_name' => 'Maria', 'shipping_phone' => '1', 'shipping_zip' => '13010000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);
    }

    public function test_ouvinte_grava_o_aviso_despacha_o_job_e_tira_da_fila(): void
    {
        Queue::fake();
        $aviso = json_encode($this->aviso('Unshipped'));
        $fila = $this->filaFalsa([$aviso, $aviso, 'lixo que não é json']);

        $this->artisan('amazon:notificacoes-ouvir', ['--uma-vez' => true])->assertSuccessful();

        $registro = AmazonNotificacao::sole();
        $this->assertSame(self::NUMERO, $registro->amazon_order_id);
        $this->assertSame('Unshipped', $registro->order_status);
        $this->assertSame(AmazonNotificacao::STATUS_RECEBIDA, $registro->status);
        // Repetida (SQS Standard entrega mais de uma vez): um job só.
        Queue::assertPushed(ProcessarNotificacaoAmazon::class, 1);
        // As três saem da fila, inclusive a repetida e a estranha.
        $this->assertSame(['r0', 'r1', 'r2'], $fila->apagadas);
    }

    public function test_so_registrar_nao_mexe_em_pedido(): void
    {
        Queue::fake();
        $this->filaFalsa([json_encode($this->aviso('Canceled'))]);

        $this->artisan('amazon:notificacoes-ouvir', ['--uma-vez' => true, '--so-registrar' => true])->assertSuccessful();

        $this->assertSame(1, AmazonNotificacao::count());
        Queue::assertNothingPushed();
    }

    public function test_sem_fila_configurada_nao_liga(): void
    {
        $this->artisan('amazon:notificacoes-ouvir', ['--uma-vez' => true])->assertFailed();
    }

    public function test_cancelamento_na_amazon_cancela_o_pedido_que_ja_existe(): void
    {
        $order = $this->pedidoPago();
        $aviso = AmazonNotificacao::registrar($this->aviso('Canceled'));

        dispatch_sync(new ProcessarNotificacaoAmazon($aviso->id));

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(AmazonNotificacao::STATUS_PROCESSADA, $aviso->fresh()->status);
    }

    public function test_status_pendente_nao_muda_pedido_existente(): void
    {
        $order = $this->pedidoPago();
        $aviso = AmazonNotificacao::registrar($this->aviso('Pending'));

        dispatch_sync(new ProcessarNotificacaoAmazon($aviso->id));

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(AmazonNotificacao::STATUS_IGNORADA, $aviso->fresh()->status);
    }

    public function test_pedido_novo_pago_importa_pelo_caminho_de_sempre(): void
    {
        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')->once()->with(MarketplaceAccount::CHANNEL_AMAZON, self::NUMERO)->andReturnUsing(fn () => $this->pedidoPago());
        $this->app->instance(OrderImportService::class, $importer);
        $aviso = AmazonNotificacao::registrar($this->aviso('Unshipped'));

        dispatch_sync(new ProcessarNotificacaoAmazon($aviso->id));

        $this->assertSame(AmazonNotificacao::STATUS_PROCESSADA, $aviso->fresh()->status);
    }

    public function test_pedido_que_o_bling_ainda_nao_tem_tenta_de_novo(): void
    {
        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldReceive('import')->once()->andThrow(new RuntimeException('Pedido não encontrado na loja do canal amazon conectada ao Bling.'));
        $this->app->instance(OrderImportService::class, $importer);
        $aviso = AmazonNotificacao::registrar($this->aviso('Unshipped'));

        dispatch_sync(new ProcessarNotificacaoAmazon($aviso->id));

        $aviso->refresh();
        $this->assertSame(AmazonNotificacao::STATUS_RECEBIDA, $aviso->status);
        $this->assertStringContainsString('Tentativa 1', $aviso->resultado);
    }

    public function test_pedido_que_nem_existe_e_ja_veio_cancelado_nao_importa(): void
    {
        $importer = Mockery::mock(OrderImportService::class);
        $importer->shouldNotReceive('import');
        $this->app->instance(OrderImportService::class, $importer);
        $aviso = AmazonNotificacao::registrar($this->aviso('Canceled'));

        dispatch_sync(new ProcessarNotificacaoAmazon($aviso->id));

        $this->assertSame(AmazonNotificacao::STATUS_IGNORADA, $aviso->fresh()->status);
    }

    public function test_configurar_cria_destino_grantless_e_assinatura(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'token-vendedor',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'token-grantless', 'expires_in' => 3600]),
            '*/notifications/v1/destinations' => Http::sequence()
                ->push(['payload' => []])
                ->push(['payload' => ['destinationId' => 'dest-1', 'name' => 'kazakora-sqs']]),
            '*/notifications/v1/subscriptions/ORDER_CHANGE' => Http::sequence()
                ->push(['errors' => [['code' => 'NotFound', 'message' => 'not found']]], 404)
                ->push(['payload' => ['subscriptionId' => 'sub-1', 'destinationId' => 'dest-1']]),
        ]);

        $this->artisan('amazon:notificacoes-configurar')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'auth/o2/token')
            && $r['grant_type'] === 'client_credentials'
            && $r['scope'] === 'sellingpartnerapi::notifications');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/notifications/v1/destinations')
            && $r->hasHeader('x-amz-access-token', 'token-grantless')
            && $r['resourceSpecification']['sqs']['arn'] === 'arn:aws:sqs:us-east-1:123456789012:kazakora-amazon-notificacoes');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/notifications/v1/subscriptions/ORDER_CHANGE')
            && $r->hasHeader('x-amz-access-token', 'token-vendedor')
            && $r['payloadVersion'] === '1.0'
            && $r['destinationId'] === 'dest-1');
    }
}
