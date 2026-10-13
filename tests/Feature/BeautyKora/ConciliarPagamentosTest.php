<?php

namespace Tests\Feature\BeautyKora;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pagamento confirmado do Beauty Kora (pedido 2026-10-12): o conciliador lê
 * os pedidos pagos na Hostinger e marca o lote certo daqui como pago.
 */
class ConciliarPagamentosTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://developers.hostinger.com/api/ecommerce/v1/stores';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.beautykora.hostinger.token' => 'token-teste',
            'services.beautykora.hostinger.store_id' => 'store_1',
        ]);
        $this->travelTo('2026-10-12 20:00:00');
    }

    private function lote(string $lote, string $email, array $variantes, string $quando = '2026-10-12 19:50:00'): void
    {
        $cliente = BeautyKoraCliente::query()->firstOrCreate(['email' => 'maria@exemplo.com'], ['name' => 'Maria', 'password' => 'segredo123']);
        foreach ($variantes as $v) {
            $p = BeautyKoraPedido::query()->create([
                'cliente_id' => $cliente->id, 'produto_id' => 'prod_'.$v, 'variante_id' => $v, 'produto_nome' => 'Item '.$v,
                'quantidade' => 1, 'valor_pago_centavos' => 1000, 'lote' => $lote, 'email_checkout' => $email,
            ]);
            $p->forceFill(['created_at' => $quando])->save();
        }
    }

    /** @param list<array<string, mixed>> $pagos */
    private function hostinger(array $pagos, array $estornados = []): void
    {
        Http::fake(function ($request) use ($pagos, $estornados) {
            $url = $request->url();
            if (str_contains($url, '/orders?')) {
                $lista = str_contains(urldecode($url), 'payment_status[0]=refunded') ? $estornados : $pagos;

                return Http::response(['data' => $lista, 'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => count($lista)]]);
            }
            foreach ($pagos as $p) {
                if (str_ends_with($url, '/orders/'.$p['id'])) {
                    return Http::response(['data' => $p]);
                }
            }

            return Http::response([], 404);
        });
    }

    public function test_pedido_pago_na_hostinger_marca_o_lote_certo_como_pago(): void
    {
        $this->lote('lote-a', 'maria@exemplo.com', ['v1', 'v2']);
        $this->lote('lote-b', 'maria@exemplo.com', ['v3']);
        $this->hostinger([[
            'id' => 'ord_1', 'display_id' => 1042, 'payment_status' => 'captured', 'customer_email' => 'Maria@Exemplo.com',
            'created_at' => '2026-10-12T22:55:00Z', 'items' => [['variant_id' => 'v1'], ['variant_id' => 'v2']],
        ]]);

        $this->artisan('beautykora:conciliar-pagamentos')->expectsOutputToContain('Pagos: 1.')->assertSuccessful();

        $this->assertSame(['paid', 'paid'], BeautyKoraPedido::query()->where('lote', 'lote-a')->pluck('status')->all());
        $this->assertSame(1042, BeautyKoraPedido::query()->where('lote', 'lote-a')->first()->hostinger_numero);
        $this->assertSame('pending', BeautyKoraPedido::query()->where('lote', 'lote-b')->value('status'));
        $this->assertSame(2, AuditLog::query()->where('entity', 'BeautyKoraPedido')->count());

        // Rodar de novo não repete nada.
        $this->artisan('beautykora:conciliar-pagamentos')->expectsOutputToContain('Pagos: 0.')->assertSuccessful();
    }

    public function test_email_diferente_ou_hora_longe_nao_marca(): void
    {
        $this->lote('lote-a', 'maria@exemplo.com', ['v1'], '2026-10-12 12:00:00');
        $this->hostinger([[
            'id' => 'ord_1', 'display_id' => 7, 'payment_status' => 'captured', 'customer_email' => 'maria@exemplo.com',
            'created_at' => '2026-10-12T22:55:00Z', 'items' => [['variant_id' => 'v1']],
        ], [
            'id' => 'ord_2', 'display_id' => 8, 'payment_status' => 'captured', 'customer_email' => 'outra@exemplo.com',
            'created_at' => '2026-10-12T15:05:00Z', 'items' => [['variant_id' => 'v1']],
        ]]);

        $this->artisan('beautykora:conciliar-pagamentos')->expectsOutputToContain('sem pedido correspondente aqui: #7, #8')->assertSuccessful();
        $this->assertSame('pending', BeautyKoraPedido::query()->value('status'));
    }

    public function test_estorno_cancela_o_pedido_ja_pago(): void
    {
        $this->lote('lote-a', 'maria@exemplo.com', ['v1']);
        BeautyKoraPedido::query()->update(['status' => 'paid', 'hostinger_pedido_id' => 'ord_1', 'hostinger_numero' => 5]);
        $this->hostinger([], [['id' => 'ord_1', 'display_id' => 5, 'payment_status' => 'refunded']]);

        $this->artisan('beautykora:conciliar-pagamentos')->expectsOutputToContain('Estornados: 1.')->assertSuccessful();
        $this->assertSame('canceled', BeautyKoraPedido::query()->value('status'));
    }

    public function test_sem_token_so_avisa(): void
    {
        config(['services.beautykora.hostinger.token' => null]);
        Http::fake();

        $this->artisan('beautykora:conciliar-pagamentos')->expectsOutputToContain('não configurado')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_checkout_grava_lote_variante_e_email(): void
    {
        $token = $this->postJson('/api/beautykora/cadastro', ['name' => 'Ana', 'email' => 'ana@exemplo.com', 'password' => 'segredo123'])->json('token');

        $this->withToken($token)->postJson('/api/beautykora/pedidos', [
            'email' => 'Ana.Checkout@exemplo.com',
            'items' => [
                ['product_id' => 'p1', 'variant_id' => 'v1', 'product_name' => 'A', 'quantity' => 1, 'valor_pago' => 100],
                ['product_id' => 'p2', 'variant_id' => 'v2', 'product_name' => 'B', 'quantity' => 1, 'valor_pago' => 100],
            ],
        ])->assertCreated();

        $pedidos = BeautyKoraPedido::query()->get();
        $this->assertCount(1, $pedidos->pluck('lote')->unique());
        $this->assertSame(['v1', 'v2'], $pedidos->pluck('variante_id')->all());
        $this->assertSame('ana.checkout@exemplo.com', $pedidos->first()->email_checkout);
    }
}
