<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use App\Modules\Cart\Mail\CarrinhoAbandonado;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Cart\Support\LembreteCarrinho;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Carrinho abandonado (pedido 2026-10-10): 50 min, depois 1 por dia por 7 dias. */
class CarrinhoAbandonadoTest extends TestCase
{
    use RefreshDatabase;

    private function carrinho(array $extra = []): CartSnapshot
    {
        $produto = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);

        return CartSnapshot::create([
            'session_id' => 'sessao-'.uniqid(),
            'items_count' => 1,
            'total' => 100,
            'email' => 'cliente@exemplo.com',
            'itens' => [$produto->id => 1],
            'ultima_atividade_em' => now(),
            ...$extra,
        ]);
    }

    public function test_calendario_50_minutos_depois_manha_tarde_e_noite(): void
    {
        $carrinho = $this->carrinho(['ultima_atividade_em' => Carbon::parse('2026-10-10 15:00')]);

        $this->assertEquals('2026-10-10 15:50', LembreteCarrinho::proximoEm($carrinho)->format('Y-m-d H:i'));

        $esperado = ['2026-10-11 09:00', '2026-10-12 14:00', '2026-10-13 20:00', '2026-10-14 09:00', '2026-10-15 14:00', '2026-10-16 20:00', '2026-10-17 09:00'];
        foreach ($esperado as $i => $quando) {
            $carrinho->lembretes_enviados = $i + 1;
            $carrinho->ultimo_lembrete_em = null;
            $this->assertEquals($quando, LembreteCarrinho::proximoEm($carrinho)->format('Y-m-d H:i'));
        }

        $carrinho->lembretes_enviados = 8;
        $this->assertNull(LembreteCarrinho::proximoEm($carrinho));
    }

    public function test_primeiro_lembrete_so_depois_de_50_minutos(): void
    {
        Mail::fake();
        $carrinho = $this->carrinho(['ultima_atividade_em' => now()->subMinutes(40)]);

        $this->artisan('loja:carrinho-abandonado')->assertSuccessful();
        Mail::assertNothingSent();

        $this->travel(11)->minutes();
        $this->artisan('loja:carrinho-abandonado')->assertSuccessful();

        Mail::assertSent(CarrinhoAbandonado::class, fn (CarrinhoAbandonado $mail) => $mail->hasTo('cliente@exemplo.com') && $mail->numero === 1);
        $this->assertSame(1, $carrinho->fresh()->lembretes_enviados);

        // Rodando de novo na mesma hora não repete.
        $this->artisan('loja:carrinho-abandonado');
        Mail::assertSentCount(1);
    }

    public function test_para_quando_o_cliente_ja_comprou(): void
    {
        Mail::fake();
        $carrinho = $this->carrinho(['ultima_atividade_em' => now()->subHours(2)]);
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'shipping_email' => 'cliente@exemplo.com',
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);
        $pedido->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('loja:carrinho-abandonado');

        Mail::assertNothingSent();
        $this->assertTrue($carrinho->fresh()->lembretes_parados);
    }

    public function test_para_quando_cliente_saiu_das_promocoes(): void
    {
        Mail::fake();
        $cliente = User::factory()->create(['email' => 'cliente@exemplo.com']);
        $cliente->forceFill(['recebe_promocoes' => false])->save();
        $this->carrinho(['ultima_atividade_em' => now()->subHours(2), 'user_id' => $cliente->id]);

        $this->artisan('loja:carrinho-abandonado');

        Mail::assertNothingSent();
    }

    public function test_link_do_email_devolve_o_carrinho_e_link_de_parar(): void
    {
        $carrinho = $this->carrinho(['lembretes_enviados' => 2]);
        $produtoId = array_key_first($carrinho->itens);

        $this->get(URL::signedRoute('carrinho.recuperar', ['carrinho' => $carrinho->id]))->assertRedirect('/carrinho');
        $this->assertSame([$produtoId => 1], session('cart'));

        $novo = CartSnapshot::where('email', 'cliente@exemplo.com')->sole();
        $this->assertSame(2, $novo->lembretes_enviados);

        $this->get(URL::signedRoute('carrinho.lembretes.parar', ['carrinho' => $novo->id]))->assertRedirect('/');
        $this->assertTrue($novo->fresh()->lembretes_parados);

        $this->get(route('carrinho.lembretes.parar', ['carrinho' => $novo->id]))->assertForbidden();
    }

    public function test_visitante_que_digitou_email_no_checkout_fica_no_carrinho(): void
    {
        $produto = Product::factory()->create(['price' => 100, 'stock' => 10, 'is_active' => true]);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        app(\App\Modules\Cart\Support\CartManager::class)->lembrarEmail('Visitante@Exemplo.com');

        $snapshot = CartSnapshot::sole();
        $this->assertSame('visitante@exemplo.com', $snapshot->email);
        $this->assertSame([$produto->id => 1], $snapshot->itens);
    }

    public function test_email_mostra_os_produtos_e_o_layout_novo(): void
    {
        $carrinho = $this->carrinho();

        $html = (new CarrinhoAbandonado($carrinho, 1))->render();

        $this->assertStringContainsString(Product::first()->name, $html);
        $this->assertStringContainsString('Compra Segura', $html);
        $this->assertStringContainsString('65.604.590/0001-07', $html);
        $this->assertStringContainsString('wa.me/5511965723990', $html);
        $this->assertStringContainsString('/images/marca/logo-nav.png', $html);
    }
}
