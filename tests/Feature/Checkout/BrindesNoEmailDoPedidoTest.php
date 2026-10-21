<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Chave da promoção (pedido 2026-10-21): ligada, o e-mail de confirmação do
 * pedido do kazakora.com leva a frase de presente e os PDFs dos livros
 * ativos dos produtos comprados; desligada, sai sem os livros.
 */
class BrindesNoEmailDoPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->produto = Product::factory()->create(['is_active' => true]);

        foreach (['Mais Saúde', 'Saúde e Vida', 'Sono & Saúde'] as $i => $titulo) {
            Storage::disk('local')->put("livros-digitais/{$i}.pdf", '%PDF-1.4 fake');
            LivroDigital::query()->create([
                'titulo' => $titulo,
                'arquivo' => "livros-digitais/{$i}.pdf",
                'nome_arquivo' => "{$titulo}.pdf",
                'tamanho' => 13,
                'ativo' => true,
                'todos_produtos' => true,
            ]);
        }
    }

    private function pedido(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => User::factory()->create(['role' => User::ROLE_CUSTOMER])->id,
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $extra));

        $order->items()->create([
            'product_id' => $this->produto->id,
            'product_name' => $this->produto->name,
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'item_type' => OrderItem::TYPE_PRODUCT,
        ]);

        return $order;
    }

    public function test_chave_ligada_anexa_os_tres_livros_e_mostra_a_frase(): void
    {
        LivroDigital::ligarEmailPedido(true);

        $mail = new OrderConfirmation($this->pedido());

        $this->assertCount(3, $mail->attachments());
        $mail->assertSeeInHtml('Um presente para você!');
        $mail->assertSeeInHtml('Sono &amp; Saúde', false);
    }

    public function test_chave_desligada_nao_manda_os_livros(): void
    {
        LivroDigital::ligarEmailPedido(false);

        $mail = new OrderConfirmation($this->pedido());

        $this->assertCount(0, $mail->attachments());
        $mail->assertDontSeeInHtml('Um presente para você!');
    }

    public function test_livro_desativado_e_pedido_de_marketplace_ficam_fora(): void
    {
        LivroDigital::ligarEmailPedido(true);
        LivroDigital::query()->where('titulo', 'Mais Saúde')->update(['ativo' => false]);

        $this->assertCount(2, (new OrderConfirmation($this->pedido()))->attachments());
        $this->assertCount(0, (new OrderConfirmation($this->pedido(['origin' => Order::ORIGIN_SHOPEE])))->attachments());
        $this->assertCount(0, (new OrderConfirmation($this->pedido(['site_origem' => Order::SITE_FERRAKORA])))->attachments());
    }

    public function test_admin_liga_e_desliga_a_chave(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->patch('/admin/entregaveis/livros-digitais/email-pedido')->assertRedirect();
        $this->assertTrue(LivroDigital::emailPedidoLigado());

        $this->actingAs($admin)->get('/admin/entregaveis/livros-digitais')
            ->assertInertia(fn ($page) => $page->where('emailPedido', true));

        $this->actingAs($admin)->patch('/admin/entregaveis/livros-digitais/email-pedido')->assertRedirect();
        $this->assertFalse(LivroDigital::emailPedidoLigado());
    }
}
