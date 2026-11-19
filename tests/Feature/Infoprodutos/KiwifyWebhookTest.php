<?php

namespace Tests\Feature\Infoprodutos;

use App\Modules\Infoprodutos\Models\InfoprodutoCarrinho;
use App\Modules\Infoprodutos\Models\InfoprodutoVenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Webhook da Kiwify (POST /api-infoprodutos/kiwify/webhook, 2026-11-19):
 * assinatura HMAC-SHA1 do corpo com o token, idempotência por venda +
 * evento e nada de dado pessoal do comprador no banco.
 */
class KiwifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api-infoprodutos/kiwify/webhook';

    private const TOKEN = 'token-do-webhook';

    protected function setUp(): void
    {
        parent::setUp();
        config(['infoprodutos.kiwify.tokens' => [self::TOKEN]]);
    }

    private function enviar(array $dados, ?string $assinatura = null)
    {
        $corpo = json_encode($dados);
        $assinatura ??= hash_hmac('sha1', $corpo, self::TOKEN);

        return $this->call('POST', self::URL.'?signature='.$assinatura, [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo);
    }

    private function aprovada(array $extra = []): array
    {
        return array_replace_recursive([
            'order_id' => 'ord-1',
            'order_status' => 'paid',
            'webhook_event_type' => 'order_approved',
            'payment_method' => 'credit_card',
            'created_at' => '2026-11-19 10:00',
            'approved_date' => '2026-11-19 10:01',
            'Product' => ['product_id' => 'prod-pidelo', 'product_name' => 'Pídelo Bien'],
            'Customer' => ['full_name' => 'Maria Pérez', 'email' => 'maria@example.com', 'mobile' => '+5215555555555', 'CPF' => '12345678900', 'ip' => '198.51.100.9', 'country' => 'MX'],
            'Commissions' => ['charge_amount' => 700, 'currency' => 'USD', 'my_commission' => 3150, 'settlement_amount_currency' => 'BRL'],
            'TrackingParameters' => ['utm_source' => 'facebook', 'utm_campaign' => 'pidelo-frio', 'utm_content' => '120211', 'src' => 'hero', 'sck' => 'AbCdEf0123456789xyz'],
        ], $extra);
    }

    public function test_sem_assinatura_ou_com_token_errado_e_401(): void
    {
        $this->call('POST', self::URL, [], [], [], [], json_encode($this->aprovada()))->assertStatus(401);
        $this->enviar($this->aprovada(), hash_hmac('sha1', json_encode($this->aprovada()), 'outro-token'))->assertStatus(401);
        $this->assertSame(0, InfoprodutoVenda::count());
    }

    public function test_vetor_da_documentacao_da_kiwify(): void
    {
        config(['infoprodutos.kiwify.tokens' => ['kiwify-token-de-teste']]);
        $corpo = '{"order_id":"9b1c2d3e-0000-4000-8000-000000000001","order_status":"paid","webhook_event_type":"order_approved"}';

        $this->call('POST', self::URL.'?signature=bf18f352a4f981b91dfa3f6d8742a81080ab8c86', [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)
            ->assertOk();
        $this->assertSame(InfoprodutoVenda::APROVADA, InfoprodutoVenda::sole()->status);
    }

    public function test_grava_a_venda_sem_dado_pessoal(): void
    {
        $this->enviar($this->aprovada())->assertOk()->assertJson(['ok' => true]);

        $venda = InfoprodutoVenda::sole();
        $this->assertSame('pidelo-bien', $venda->produto);
        $this->assertSame('principal', $venda->tipo);
        $this->assertSame('7.00', $venda->valor);
        $this->assertSame('USD', $venda->moeda);
        $this->assertSame('31.50', $venda->valor_liquido);
        $this->assertSame('MX', $venda->pais);
        $this->assertSame(['120211', 'hero', 'AbCdEf0123456789xyz'], [$venda->utm_content, $venda->src, $venda->sck]);

        $tudo = json_encode(DB::table('infoproduto_vendas')->get()).json_encode(DB::table('infoproduto_kiwify_avisos')->get());
        foreach (['Maria', 'maria@example.com', '5555555555', '12345678900', '198.51.100.9'] as $pessoal) {
            $this->assertStringNotContainsString($pessoal, $tudo);
        }
    }

    public function test_mesmo_aviso_duas_vezes_conta_uma(): void
    {
        $this->enviar($this->aprovada())->assertOk();
        $this->enviar($this->aprovada())->assertOk()->assertJson(['repetido' => true]);

        $this->assertSame(1, InfoprodutoVenda::count());
        $this->assertSame(1, DB::table('infoproduto_kiwify_avisos')->count());
    }

    public function test_status_so_anda_para_frente_e_reembolso_marca_a_data(): void
    {
        $this->enviar($this->aprovada())->assertOk();
        // Pix gerado chegando atrasado não desfaz a aprovação.
        $this->enviar($this->aprovada(['webhook_event_type' => 'pix_created', 'order_status' => 'waiting_payment']))->assertOk();
        $this->assertSame(InfoprodutoVenda::APROVADA, InfoprodutoVenda::sole()->status);

        $this->enviar($this->aprovada(['webhook_event_type' => 'order_refunded', 'order_status' => 'refunded']))->assertOk();
        $venda = InfoprodutoVenda::sole();
        $this->assertSame(InfoprodutoVenda::REEMBOLSADA, $venda->status);
        $this->assertNotNull($venda->reembolsada_em);
        $this->assertNotNull($venda->aprovada_em);
    }

    public function test_classifica_upsell_e_order_bump(): void
    {
        $this->enviar($this->aprovada(['order_id' => 'ord-2', 'Product' => ['product_id' => 'a9477cd0-e3e0-11f1-bf8d-b5754714ca38', 'product_name' => 'Kit Productividad con IA']]))->assertOk();
        $this->enviar($this->aprovada(['order_id' => 'ord-3', 'Product' => ['product_id' => '5761e950-e3e0-11f1-b479-3f89c662da05', 'product_name' => 'Prompts de Excel']]))->assertOk();
        // Produto fora do mapa pendurado em outro pedido = complemento daquele pedido.
        $this->enviar($this->aprovada(['order_id' => 'ord-1']))->assertOk();
        $this->enviar($this->aprovada(['order_id' => 'ord-4', 'parent_order_id' => 'ord-1', 'Product' => ['product_id' => 'novo', 'product_name' => 'Lucas']]))->assertOk();

        $this->assertSame(['pidelo-bien', 'upsell'], [InfoprodutoVenda::where('kiwify_order_id', 'ord-2')->value('produto'), InfoprodutoVenda::where('kiwify_order_id', 'ord-2')->value('tipo')]);
        $this->assertSame('order_bump', InfoprodutoVenda::where('kiwify_order_id', 'ord-3')->value('tipo'));
        $this->assertSame(['pidelo-bien', 'order_bump'], [InfoprodutoVenda::where('kiwify_order_id', 'ord-4')->value('produto'), InfoprodutoVenda::where('kiwify_order_id', 'ord-4')->value('tipo')]);
    }

    public function test_carrinho_abandonado_sem_dado_pessoal(): void
    {
        $carrinho = ['id' => 'cart-9', 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '11999999999', 'country' => 'CO',
            'product_id' => 'x', 'product_name' => 'Busy Book ABC en mis manos', 'checkout_link' => 'https://pay.kiwify.com/x', 'status' => 'abandoned', 'created_at' => '2026-11-19 09:00'];
        $this->enviar($carrinho)->assertOk();
        $this->enviar($carrinho)->assertOk()->assertJson(['repetido' => true]);

        $c = InfoprodutoCarrinho::sole();
        $this->assertSame(['abc-en-mis-manos', 'CO'], [$c->produto, $c->pais]);
        $this->assertStringNotContainsString('ana@example.com', json_encode(DB::table('infoproduto_carrinhos')->get()));
    }
}
