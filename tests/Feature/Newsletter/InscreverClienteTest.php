<?php

namespace Tests\Feature\Newsletter;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Newsletter\Models\NewsletterInscrito;
use App\Modules\Newsletter\Support\InscreverCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cliente que compra (pago) na KazaKora entra no Caderno KazaKora; quem saiu não volta (25/11/2026). */
class InscreverClienteTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $status, string $email = 'maria@exemplo.com', ?string $site = null): Order
    {
        $user = User::factory()->create(['email' => $email]);

        return Order::create([
            'user_id' => $user->id, 'status' => $status, 'origin' => Order::ORIGIN_STORE, 'site_origem' => $site,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '01001-000',
            'shipping_street' => 'Praça da Sé', 'shipping_number' => '1', 'shipping_neighborhood' => 'Sé',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'total' => 100,
        ]);
    }

    public function test_pedido_pago_inscreve_ativo_com_a_prova(): void
    {
        $this->assertSame('inscrito', app(InscreverCliente::class)->doPedido($this->pedido(Order::STATUS_PAID)));

        $inscrito = NewsletterInscrito::sole();
        $this->assertSame(NewsletterInscrito::ATIVO, $inscrito->status);
        $this->assertSame(InscreverCliente::VERSAO, $inscrito->consentimento_versao);
        $this->assertNotNull($inscrito->token);
    }

    public function test_pedido_nao_pago_ou_de_outro_site_nao_inscreve(): void
    {
        $this->assertSame('ignorado', app(InscreverCliente::class)->doPedido($this->pedido(Order::STATUS_AWAITING_PAYMENT)));
        $this->assertSame('ignorado', app(InscreverCliente::class)->doPedido($this->pedido(Order::STATUS_PAID, 'joao@exemplo.com', 'ferrakora')));
        $this->assertSame(0, NewsletterInscrito::count());
    }

    public function test_quem_saiu_nao_volta_e_pendente_vira_ativo(): void
    {
        NewsletterInscrito::create(['email' => 'maria@exemplo.com', 'status' => NewsletterInscrito::DESCADASTRADO]);
        $this->assertSame('descadastrado', app(InscreverCliente::class)->doPedido($this->pedido(Order::STATUS_PAID)));
        $this->assertSame(NewsletterInscrito::DESCADASTRADO, NewsletterInscrito::sole()->status);

        NewsletterInscrito::create(['email' => 'ana@exemplo.com', 'status' => NewsletterInscrito::PENDENTE]);
        $this->assertSame('confirmado', app(InscreverCliente::class)->doPedido($this->pedido(Order::STATUS_PAID, 'ana@exemplo.com')));
        $this->assertTrue(NewsletterInscrito::where('email', 'ana@exemplo.com')->sole()->ativo());
    }

    public function test_comando_inscreve_os_clientes_que_ja_compraram(): void
    {
        $this->pedido(Order::STATUS_COMPLETED, 'a@exemplo.com');
        $this->pedido(Order::STATUS_CANCELLED, 'b@exemplo.com');

        $this->artisan('newsletter:inscrever-clientes')->assertSuccessful();

        $this->assertSame(['a@exemplo.com'], NewsletterInscrito::pluck('email')->all());
    }
}
