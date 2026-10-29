<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\LivroDownloadToken;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Promocao;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Chave da promoção (pedido 2026-10-21): ligada, o e-mail de confirmação do
 * pedido do kazakora.com leva a frase de presente e os PDFs dos livros
 * ativos dos produtos comprados; desligada, só o link. O link de download
 * (e-mail e página do pedido) vale para os livros gravados no pedido.
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

    public function test_resumo_lista_livros_como_itens_de_valor_zero_e_nota_fora_da_lista(): void
    {
        $order = $this->pedido();
        Storage::disk('local')->put('danfe/1.pdf', '%PDF-1.4 fake');
        $order->invoice()->create([
            'danfe_path' => 'danfe/1.pdf',
            'status' => \App\Modules\Fiscal\Models\Invoice::STATUS_AUTHORIZED,
            'serie' => 1,
            'numero' => 1,
            'chave_acesso' => str_repeat('1', 44),
        ]);

        $html = (new OrderConfirmation($order->fresh()))->render();

        $this->assertStringContainsString($this->produto->name, $html);
        // 2026-10-28: a nota não é produto; só o quadro do anexo fala dela.
        $this->assertStringNotContainsString('Nota Fiscal (PDF em anexo)', $html);
        $this->assertStringContainsString('A Nota Fiscal do seu pedido está em anexo', $html);
        $this->assertStringContainsString('Sono &amp; Saúde (livro digital em PDF)', $html);
        $this->assertSame(3, substr_count($html, 'R$ 0,00'));
    }

    public function test_sem_miniatura_dos_livros_nem_frase_de_gratis(): void
    {
        $mail = new OrderConfirmation($this->pedido());

        $mail->assertDontSeeInHtml(asset(PromocoesDoProduto::QUADRO_LIVROS_PADRAO), false);
        $mail->assertDontSeeInHtml('livros-miniatura', false);
        $mail->assertDontSeeInHtml('livros digitais GRÁTIS');
    }

    public function test_chave_desligada_manda_so_o_link_dos_livros(): void
    {
        LivroDigital::ligarEmailPedido(false);

        $order = $this->pedido();
        $mail = new OrderConfirmation($order);

        $this->assertCount(0, $mail->attachments());
        $mail->assertSeeInHtml('Um presente para você!');
        $mail->assertSeeInHtml('/livros/baixar/', false);
        $mail->assertSeeInHtml('vale para um download');
    }

    public function test_promocao_pdfs_fora_da_pagina_do_produto_nao_da_livro(): void
    {
        Promocao::query()->where('slug', Promocao::PDFS)->update(['na_pagina_produto' => false]);
        PromocoesDoProduto::limparCache();

        $mail = new OrderConfirmation($this->pedido());

        $this->assertCount(0, $mail->brindes);
        $mail->assertDontSeeInHtml('Um presente para você!');
    }

    public function test_livro_gravado_na_compra_continua_com_o_cliente(): void
    {
        $order = $this->pedido();
        $this->assertCount(3, LivroDigital::garantirParaPedido($order));

        // A promoção acaba e um livro é desativado depois da compra.
        Promocao::query()->where('slug', Promocao::PDFS)->update(['na_pagina_produto' => false]);
        LivroDigital::query()->where('titulo', 'Mais Saúde')->update(['ativo' => false]);
        PromocoesDoProduto::limparCache();

        $this->assertCount(3, LivroDigital::doPedido($order));
        $this->assertCount(3, (new OrderConfirmation($order))->brindes);
    }

    public function test_link_com_token_baixa_o_pdf_uma_vez_so(): void
    {
        $order = $this->pedido();
        $livro = LivroDigital::garantirParaPedido($order)->first();

        $url = $livro->urlDoPedido($order);
        $acesso = LivroDownloadToken::query()->sole();

        // Token gravado no banco, ligado ao pedido e ao arquivo; o mesmo link
        // no e-mail e na página do pedido.
        $this->assertStringEndsWith('/livros/baixar/'.$acesso->token, $url);
        $this->assertSame([$order->id, $livro->id], [$acesso->order_id, $acesso->livro_digital_id]);
        $this->assertSame($url, $livro->urlDoPedido($order));

        // O link abre os termos de uso; o PDF só sai no "Concordar e baixar".
        $this->withoutVite()->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/LivroTermos', false)
                ->where('livro.titulo', $livro->titulo)
                ->where('esgotado', null)
                ->where('aceitarUrl', $url));
        $this->assertSame(0, (int) DB::table(LivroDigital::TABELA_PEDIDO)->where('order_id', $order->id)->where('livro_digital_id', $livro->id)->value('downloads'));

        $this->post($url)->assertSessionHasErrors('aceite');

        $this->post($url, ['aceite' => '1'])
            ->assertOk()
            ->assertDownload();

        $linha = DB::table(LivroDigital::TABELA_PEDIDO)->where('order_id', $order->id)->where('livro_digital_id', $livro->id)->first();
        $this->assertSame(1, (int) $linha->downloads);
        $this->assertNotNull($linha->termos_aceitos_em);
        $this->assertSame('127.0.0.1', $linha->termos_aceitos_ip);
        $this->assertNotNull($acesso->fresh()->usado_em);

        // Segundo download (ou outra pessoa com o link): bloqueado.
        $this->post($url, ['aceite' => '1'])->assertStatus(410);
        $this->withoutVite()->get($url)
            ->assertStatus(410)
            ->assertInertia(fn ($page) => $page->where('esgotado', fn ($motivo) => str_contains($motivo, 'já foi usado')));
        $this->assertSame(1, (int) DB::table(LivroDigital::TABELA_PEDIDO)->where('order_id', $order->id)->where('livro_digital_id', $livro->id)->value('downloads'));

        // Recarregar a página do pedido não gera link novo.
        $this->assertSame($url, $livro->urlDoPedido($order));
        $this->assertSame(1, LivroDownloadToken::query()->count());
    }

    public function test_link_bloqueia_depois_do_limite_de_tentativas(): void
    {
        $order = $this->pedido();
        $livro = LivroDigital::garantirParaPedido($order)->first();
        $url = $livro->urlDoPedido($order);

        for ($i = 0; $i < LivroDownloadToken::MAX_TENTATIVAS; $i++) {
            $this->withoutVite()->get($url)->assertOk();
        }

        $this->withoutVite()->get($url)->assertStatus(410);
        $this->assertSame(LivroDownloadToken::MAX_TENTATIVAS, LivroDownloadToken::query()->sole()->tentativas);
    }

    public function test_token_invalido_sem_pagamento_ou_link_antigo(): void
    {
        $order = $this->pedido();
        $livro = LivroDigital::garantirParaPedido($order)->first();
        $url = $livro->urlDoPedido($order);

        $this->get('/livros/baixar/'.str_repeat('a', 64))->assertNotFound();
        $this->post('/livros/baixar/'.str_repeat('a', 64), ['aceite' => '1'])->assertNotFound();

        // Sem pagamento: não abre nem gasta tentativa.
        $order->update(['status' => Order::STATUS_AWAITING_PAYMENT]);
        $this->get($url)->assertForbidden();
        $this->post($url, ['aceite' => '1'])->assertForbidden();
        $this->assertSame(0, LivroDownloadToken::query()->sole()->tentativas);
        $order->update(['status' => Order::STATUS_PAID]);

        // Link assinado dos primeiros e-mails leva ao link com token; sem assinatura, nada.
        $this->get("/pedidos/{$order->id}/livros/{$livro->id}")->assertForbidden();
        $this->get(URL::signedRoute('pedidos.livro.baixar', ['order' => $order->id, 'livro' => $livro->id]))->assertRedirect($url);
        $outro = $this->pedido();
        $this->get(URL::signedRoute('pedidos.livro.baixar', ['order' => $outro->id, 'livro' => $livro->id]))->assertNotFound();
    }

    public function test_novo_link_revoga_o_antigo(): void
    {
        $order = $this->pedido();
        $livro = LivroDigital::garantirParaPedido($order)->first();
        $antigo = $livro->urlDoPedido($order);
        $this->post($antigo, ['aceite' => '1'])->assertDownload();

        $this->artisan('livros:novo-link', ['pedido' => $order->id, '--livro' => $livro->id])->assertSuccessful();

        $novo = $livro->urlDoPedido($order);
        $this->assertNotSame($antigo, $novo);
        $this->post($novo, ['aceite' => '1'])->assertDownload();
        $this->withoutVite()->post($antigo, ['aceite' => '1'])->assertStatus(410);
    }

    public function test_pagina_do_pedido_mostra_baixado_no_lugar_do_link(): void
    {
        $order = $this->pedido();
        $livro = LivroDigital::garantirParaPedido($order)->first();
        $this->post($livro->urlDoPedido($order), ['aceite' => '1'])->assertDownload();

        $this->withoutVite()->actingAs($order->user)->get("/pedidos/{$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('pedido.livros.itens.0.url', null)
                ->whereType('pedido.livros.itens.0.baixado_em', 'string')
                ->whereType('pedido.livros.itens.1.url', 'string'));
    }

    public function test_pagina_do_pedido_mostra_os_livros(): void
    {
        $order = $this->pedido();
        LivroDigital::garantirParaPedido($order);

        $this->withoutVite()->actingAs($order->user)->get("/pedidos/{$order->id}")
            ->assertInertia(fn ($page) => $page
                ->where('pedido.livros.liberados', true)
                ->has('pedido.livros.itens', 3)
                ->where('pedido.livros.itens.2.titulo', 'Sono & Saúde'));
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

        // Sem nada gravado, a chave vem ligada.
        $this->assertTrue(LivroDigital::emailPedidoLigado());
        $this->assertCount(3, (new OrderConfirmation($this->pedido()))->attachments());

        $this->actingAs($admin)->patch('/admin/entregaveis/livros-digitais/email-pedido')->assertRedirect();
        $this->assertFalse(LivroDigital::emailPedidoLigado());

        $this->actingAs($admin)->get('/admin/entregaveis/livros-digitais')
            ->assertInertia(fn ($page) => $page->where('emailPedido', false));

        $this->actingAs($admin)->patch('/admin/entregaveis/livros-digitais/email-pedido')->assertRedirect();
        $this->assertTrue(LivroDigital::emailPedidoLigado());
    }
}
