<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Catalog\Mail\LivroDigitalComProblema;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lira, 24/11/2026: o e-mail de um pedido de teste chegou com menos livros
 * digitais do que a página prometia. Todo livro ganho no pedido vai no
 * e-mail (anexo até o limite, link sempre); PDF sumido não some calado.
 */
class LivrosDigitaisCompletosNoEmailTest extends TestCase
{
    use RefreshDatabase;

    private const MB = 1024 * 1024;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        config([
            'services.alerta_coleta_email.ativo' => true,
            'services.alerta_coleta_email.para' => 'alerta@teste.com',
        ]);
    }

    private function livro(string $titulo, int $bytes = 13, bool $todos = true, array $produtos = []): LivroDigital
    {
        $arquivo = 'livros-digitais/'.md5($titulo).'.pdf';
        Storage::disk('local')->put($arquivo, '%PDF'.str_repeat('a', max(0, $bytes - 4)));

        $livro = LivroDigital::query()->create([
            'titulo' => $titulo,
            'arquivo' => $arquivo,
            'nome_arquivo' => "{$titulo}.pdf",
            'tamanho' => $bytes,
            'ativo' => true,
            'todos_produtos' => $todos,
        ]);
        $livro->produtos()->sync($produtos);
        PromocoesDoProduto::limparCache();

        return $livro;
    }

    /** @param  list<Product>  $produtos */
    private function pedido(array $produtos, array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => (User::query()->where('email', 'cliente@teste.com')->first()
                ?? User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'cliente@teste.com']))->id,
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

        foreach ($produtos as $produto) {
            $order->items()->create([
                'product_id' => $produto->id,
                'product_name' => $produto->name,
                'product_price' => 50,
                'quantity' => 1,
                'subtotal' => 50,
                'item_type' => OrderItem::TYPE_PRODUCT,
            ]);
        }

        return $order;
    }

    public function test_livro_sem_pdf_fica_no_email_com_link_e_avisa_o_lira_uma_vez_por_dia(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->livro('Mais Saúde');
        $sumido = $this->livro('Saúde e Vida');
        $this->livro('Sono & Saúde');
        Storage::disk('local')->delete($sumido->arquivo);

        $mail = new OrderConfirmation($this->pedido([$produto]));

        // Os 3 no corpo (o sem PDF com o link), 2 anexados.
        $this->assertCount(3, $mail->brindes);
        $this->assertCount(2, $mail->attachments());
        $mail->assertSeeInHtml('Saúde e Vida');
        $mail->assertSeeInHtml('3 livros digitais');
        $this->assertSame(3, substr_count($mail->render(), '/livros/baixar/'));

        Mail::assertSent(LivroDigitalComProblema::class, fn (LivroDigitalComProblema $m) => $m->hasTo('alerta@teste.com') && $m->problemas[0]['id'] === $sumido->id);
        $this->assertSame(1, AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::LIVROS_DIGITAIS)->where('chave', "livro:{$sumido->id}")->count());

        // Outro pedido no mesmo dia: sem novo e-mail (o sino só soma ocorrência).
        new OrderConfirmation($this->pedido([$produto]));
        Mail::assertSent(LivroDigitalComProblema::class, 1);
        $this->assertSame(1, AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::LIVROS_DIGITAIS)->count());
    }

    public function test_link_de_livro_sem_pdf_nao_gasta_o_token(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $livro = $this->livro('Mais Saúde');
        $order = $this->pedido([$produto]);
        LivroDigital::garantirParaPedido($order);
        $url = $livro->urlDoPedido($order);
        Storage::disk('local')->delete($livro->arquivo);

        $this->post($url, ['aceite' => '1'])->assertStatus(503);
        $this->assertNull($livro->tokenDoPedido($order)->usado_em);
        Mail::assertSent(LivroDigitalComProblema::class, 1);

        // PDF reposto: o mesmo link baixa.
        Storage::disk('local')->put($livro->arquivo, '%PDF-1.4 novo');
        $this->post($url, ['aceite' => '1'])->assertOk()->assertDownload();
    }

    public function test_acima_de_15_mb_anexa_os_que_cabem_e_o_resto_vai_com_link(): void
    {
        LivroDigital::ligarEmailPedido(true);
        $produto = Product::factory()->create(['is_active' => true]);
        $grande = $this->livro('Grande', 10 * self::MB);
        $medio = $this->livro('Médio', 6 * self::MB);
        $pequeno = $this->livro('Pequeno', 4 * self::MB);

        $mail = new OrderConfirmation($this->pedido([$produto]));

        // Do menor para o maior até 15 MB: 4 + 6 cabem, o de 10 vai só com link.
        $this->assertSame([$medio->id, $pequeno->id], $mail->anexados->pluck('id')->sort()->values()->all());
        $this->assertCount(2, $mail->attachments());
        $this->assertCount(3, $mail->brindes);

        $html = $mail->render();
        $this->assertSame(3, substr_count($html, '/livros/baixar/'));
        $this->assertSame(2, substr_count($html, '(em anexo)'));
        $this->assertStringContainsString('2 deles estão anexados', $html);
        $this->assertStringContainsString('Grande', $html);
        Mail::assertNotSent(LivroDigitalComProblema::class);
        $this->assertNotNull($grande->id);
    }

    public function test_livro_sozinho_maior_que_o_limite_vai_so_com_link(): void
    {
        LivroDigital::ligarEmailPedido(true);
        $produto = Product::factory()->create(['is_active' => true]);
        $this->livro('Enorme', 16 * self::MB);

        $mail = new OrderConfirmation($this->pedido([$produto]));

        $this->assertCount(1, $mail->brindes);
        $this->assertCount(0, $mail->attachments());
        $this->assertFalse($mail->livrosAnexados);
        $mail->assertSeeInHtml('/livros/baixar/', false);
    }

    public function test_pedido_com_dois_produtos_de_livros_diferentes_recebe_todos(): void
    {
        $costas = Product::factory()->create(['is_active' => true]);
        $ferramenta = Product::factory()->create(['is_active' => true]);
        $variacao = Product::factory()->create(['is_active' => true, 'parent_product_id' => $costas->id]);

        $c1 = $this->livro('Costas 1', todos: false, produtos: [$costas->id]);
        $c2 = $this->livro('Costas 2', todos: false, produtos: [$costas->id]);
        $f1 = $this->livro('Ferramentas 1', todos: false, produtos: [$ferramenta->id]);
        // "Todos os produtos" só vale para quem não tem livro próprio.
        $this->livro('Geral');

        // A variação herda os livros do pai (página, checkout e pedido iguais).
        $order = $this->pedido([$variacao, $ferramenta]);
        $mail = new OrderConfirmation($order);

        $this->assertSame([$c1->id, $c2->id, $f1->id], $mail->brindes->pluck('id')->all());
        $this->assertSame(3, PromocoesDoProduto::livrosDoCarrinho([$variacao, $ferramenta])['quantidade']);
        $this->assertSame(3, DB::table(LivroDigital::TABELA_PEDIDO)->where('order_id', $order->id)->count());
    }

    public function test_conferencia_avisa_uma_vez_ate_resolver(): void
    {
        $produto = Product::factory()->create(['is_active' => true, 'name' => 'Almofada Lombar']);
        $ok = $this->livro('Sono', todos: false, produtos: [$produto->id]);
        $sumido = $this->livro('Postura', todos: false, produtos: [$produto->id]);
        Storage::disk('local')->delete($sumido->arquivo);

        $this->artisan('livros:conferir')->assertFailed();

        Mail::assertSent(LivroDigitalComProblema::class, fn (LivroDigitalComProblema $m) => $m->hasTo('alerta@teste.com')
            && count($m->problemas) === 1
            && $m->problemas[0]['id'] === $sumido->id
            && str_contains($m->problemas[0]['produtos'][0] ?? '', 'Almofada Lombar'));
        $this->assertSame(1, AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::LIVROS_DIGITAIS)->count());

        // Mesmo problema no dia seguinte: não repete o e-mail.
        $this->artisan('livros:conferir')->assertFailed();
        Mail::assertSent(LivroDigitalComProblema::class, 1);

        // PDF reposto: passa e o alerta do sino fecha sozinho.
        Storage::disk('local')->put($sumido->arquivo, '%PDF-1.4 novo');
        $this->artisan('livros:conferir')->assertSuccessful();
        $this->assertSame(0, AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::LIVROS_DIGITAIS)->count());

        // Sumiu de novo: avisa de novo.
        Storage::disk('local')->delete($sumido->arquivo);
        $this->artisan('livros:conferir')->assertFailed();
        Mail::assertSent(LivroDigitalComProblema::class, 2);
        $this->assertNotNull($ok->id);
    }

    public function test_conferencia_seca_nao_manda_nada(): void
    {
        $sumido = $this->livro('Postura');
        Storage::disk('local')->delete($sumido->arquivo);

        $this->artisan('livros:conferir', ['--seco' => true])->assertFailed();

        Mail::assertNothingSent();
        $this->assertSame(0, AlertaAdmin::query()->count());
    }

    public function test_reenviar_confirmacao_completa_os_livros_e_reenvia(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $primeiro = $this->livro('Mais Saúde');
        $order = $this->pedido([$produto], ['external_order_id' => '2611240TESTE1']);
        DB::table(LivroDigital::TABELA_PEDIDO)->insert(['order_id' => $order->id, 'livro_digital_id' => $primeiro->id, 'created_at' => now(), 'updated_at' => now()]);

        // Ligados depois que o pedido gravou o primeiro.
        $this->livro('Saúde e Vida');
        $this->livro('Sono & Saúde');

        // Dry-run: mostra, não grava nem envia.
        $this->artisan('pedido:reenviar-confirmacao', ['pedido' => '2611240TESTE1', '--dry-run' => true])
            ->expectsOutputToContain('FALTA (seria gravado)')
            ->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertSame(1, DB::table(LivroDigital::TABELA_PEDIDO)->where('order_id', $order->id)->count());

        // De verdade, pelo id.
        $this->artisan('pedido:reenviar-confirmacao', ['pedido' => (string) $order->id])->assertSuccessful();

        $this->assertSame(3, DB::table(LivroDigital::TABELA_PEDIDO)->where('order_id', $order->id)->count());
        Mail::assertSent(OrderConfirmation::class, fn (OrderConfirmation $m) => $m->hasTo('cliente@teste.com') && $m->brindes->count() === 3);
        $this->assertDatabaseHas('order_email_logs', ['order_id' => $order->id, 'mailable' => 'order_confirmation', 'status' => 'sent']);
    }

    public function test_reenviar_recusa_pedido_sem_pagamento_e_pedido_inexistente(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $order = $this->pedido([$produto], ['status' => Order::STATUS_AWAITING_PAYMENT]);

        $this->artisan('pedido:reenviar-confirmacao', ['pedido' => (string) $order->id])->assertFailed();
        $this->artisan('pedido:reenviar-confirmacao', ['pedido' => 'NAOEXISTE'])->assertFailed();
        Mail::assertNothingSent();
    }
}
