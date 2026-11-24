<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Support\PedidoDeAvaliacao;
use App\Modules\Checkout\Mail\PedidoAvaliacao;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Pedido de avaliação 5 dias depois da entrega (pedido do Lira 2026-11-23). */
class PedidoAvaliacaoPosEntregaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'loja.pedido_avaliacao.ativo' => true,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.templates.pedido_avaliacao' => null,
        ]);
        Mail::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.AVALIACAO']]])]);
    }

    private function pedido(array $extra = [], int $diasEntregue = 6, ?Product $produto = null): Order
    {
        $order = Order::create(array_merge([
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_COMPLETED,
            'shipping_name' => 'Maria da Silva', 'shipping_phone' => '(11) 98888-7777', 'shipping_email' => 'maria@exemplo.com',
            'shipping_zip' => '03187040', 'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $extra));
        DB::table('orders')->where('id', $order->id)->update(['entregue_em' => now()->subDays($diasEntregue)]);

        $produto ??= Product::factory()->create(['is_active' => true, 'name' => 'Luminária LED']);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => $produto->name, 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $order->fresh();
    }

    private function whatsappsEnviados()
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))->values();
    }

    public function test_entrega_preenche_entregue_em(): void
    {
        $order = Order::create([
            'origin' => Order::ORIGIN_STORE, 'status' => Order::STATUS_SHIPPED, 'shipping_name' => 'Ana', 'shipping_phone' => '1', 'shipping_zip' => '1',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'C', 'shipping_state' => 'SP',
            'subtotal' => 10, 'shipping_cost' => 0, 'total' => 10,
        ]);
        $this->assertNull($order->entregue_em);

        $order->update(['status' => Order::STATUS_COMPLETED]);

        $this->assertNotNull($order->fresh()->entregue_em);
    }

    public function test_comando_escolhe_so_os_pedidos_certos(): void
    {
        $certo = $this->pedido();
        $ferrakora = $this->pedido(['site_origem' => Order::SITE_FERRAKORA, 'shipping_email' => 'ferra@exemplo.com']);
        $recente = $this->pedido(['shipping_email' => 'recente@exemplo.com'], diasEntregue: 3);
        $antigo = $this->pedido(['shipping_email' => 'antigo@exemplo.com'], diasEntregue: 90);
        $cancelado = $this->pedido(['status' => Order::STATUS_CANCELLED, 'shipping_email' => 'cancelado@exemplo.com']);
        $devolvido = $this->pedido(['shipping_email' => 'devolvido@exemplo.com']);
        DB::table('orders')->where('id', $devolvido->id)->update(['stock_restored_at' => now()]);
        $naoRecebi = $this->pedido(['shipping_email' => 'naorecebi@exemplo.com']);
        DB::table('orders')->where('id', $naoRecebi->id)->update(['whatsapp_nao_recebi_em' => now()]);
        $marketplace = $this->pedido(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'shipping_email' => 'ml@exemplo.com']);
        $enviado = $this->pedido(['shipping_email' => 'enviado@exemplo.com']);
        DB::table('orders')->where('id', $enviado->id)->update(['pedido_avaliacao_enviado_em' => now()->subDay()]);

        $this->artisan('loja:pedir-avaliacao')->assertSuccessful();

        Mail::assertSent(PedidoAvaliacao::class, 2);
        Mail::assertSent(PedidoAvaliacao::class, fn (PedidoAvaliacao $m) => $m->hasTo('maria@exemplo.com') && $m->order->is($certo));
        Mail::assertSent(PedidoAvaliacao::class, fn (PedidoAvaliacao $m) => $m->hasTo('ferra@exemplo.com'));
        $this->assertNotNull($certo->fresh()->pedido_avaliacao_enviado_em);
        $this->assertNotNull($ferrakora->fresh()->pedido_avaliacao_enviado_em);
        foreach ([$recente, $antigo, $cancelado, $devolvido, $naoRecebi, $marketplace] as $fora) {
            $this->assertNull($fora->fresh()->pedido_avaliacao_enviado_em, "pedido {$fora->id} não devia receber");
        }
        // Sem modelo de WhatsApp no .env: sai só o e-mail, sem erro.
        $this->assertCount(0, $this->whatsappsEnviados());
    }

    public function test_nao_manda_duas_vezes(): void
    {
        $this->pedido();

        $this->artisan('loja:pedir-avaliacao')->assertSuccessful();
        $this->artisan('loja:pedir-avaliacao')->assertSuccessful();

        Mail::assertSent(PedidoAvaliacao::class, 1);
    }

    public function test_desligado_nao_manda_nada(): void
    {
        config(['loja.pedido_avaliacao.ativo' => false]);
        $order = $this->pedido();

        $this->artisan('loja:pedir-avaliacao')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($order->fresh()->pedido_avaliacao_enviado_em);
    }

    public function test_com_modelo_manda_whatsapp_e_registra_na_conversa(): void
    {
        config(['services.whatsapp.templates.pedido_avaliacao' => 'pedido_avaliacao_kazakora']);
        $order = $this->pedido();

        $this->artisan('loja:pedir-avaliacao')->assertSuccessful();

        $this->assertCount(1, $this->whatsappsEnviados());
        $template = $this->whatsappsEnviados()[0][0]['template'];
        $this->assertSame('pedido_avaliacao_kazakora', $template['name']);
        $componentes = collect($template['components']);
        $this->assertSame(['Maria', 'Luminária LED'], array_column($componentes->firstWhere('type', 'body')['parameters'], 'text'));
        $sufixo = $componentes->firstWhere('type', 'button')['parameters'][0]['text'];
        $this->assertStringStartsWith($order->id.'?expires=', $sufixo);

        $mensagem = WhatsAppMessage::query()->sole();
        $this->assertSame('pedido_avaliacao_kazakora', $mensagem->payload['template']);
        $this->assertSame($order->id, $mensagem->payload['order_id']);
        Mail::assertSent(PedidoAvaliacao::class, 1);

        // O link do botão abre a página.
        $this->get(rtrim(url('/'), '/').PedidoDeAvaliacao::CAMINHO.$sufixo)->assertOk()->assertSee('Luminária LED');
    }

    public function test_link_assinado_grava_avaliacao_inclusive_nota_baixa(): void
    {
        Storage::fake('public');
        $order = $this->pedido();
        $produto = $order->items->first()->product_id;
        $link = PedidoDeAvaliacao::link($order);

        $this->get($link)->assertOk()->assertSee('Maria')->assertSee('Luminária LED')->assertSee('KazaKora');

        $this->post($link, [
            'avaliacoes' => [$produto => ['nota' => 2, 'comentario' => 'Chegou com a tampa quebrada.']],
            'fotos' => [$produto => [UploadedFile::fake()->image('foto.jpg')]],
        ])->assertRedirect($link);

        $review = Review::query()->sole();
        $this->assertSame(2, $review->rating);
        $this->assertSame('Chegou com a tampa quebrada.', $review->comment);
        $this->assertSame($order->id, (int) $review->order_id);
        $this->assertSame('Maria da Silva', $review->reviewer_name);
        $this->assertSame('SP', $review->uf);
        $this->assertSame(1, $review->images()->count());

        // Mudar a nota pelo mesmo link atualiza, não duplica.
        $this->post($link, ['avaliacoes' => [$produto => ['nota' => 4]]])->assertRedirect($link);
        $this->assertSame(4, Review::query()->sole()->rating);
    }

    public function test_cliente_com_conta_avalia_com_a_conta_dele(): void
    {
        $user = User::factory()->create(['name' => 'Joana Souza']);
        $order = $this->pedido(['user_id' => $user->id]);
        $produto = $order->items->first()->product_id;

        $this->post(PedidoDeAvaliacao::link($order), ['avaliacoes' => [$produto => ['nota' => 1]]])->assertRedirect();

        $review = Review::query()->sole();
        $this->assertSame($user->id, (int) $review->user_id);
        $this->assertSame(1, $review->rating);
        $this->assertSame('Joana Souza', $review->reviewer_display_name);
    }

    public function test_ferrakora_sai_com_a_marca_dela(): void
    {
        $order = $this->pedido(['site_origem' => Order::SITE_FERRAKORA]);

        $this->get(PedidoDeAvaliacao::link($order))->assertOk()->assertSee('FerraKora');

        $html = (new PedidoAvaliacao($order, PedidoDeAvaliacao::itens($order), 'https://exemplo.test/link'))->render();
        $this->assertStringContainsString('Avaliar minha compra', $html);
        $this->assertStringContainsString('Luminária LED', $html);
        $this->assertStringContainsString('#271E91', $html);
    }

    public function test_sem_nota_nao_grava(): void
    {
        $order = $this->pedido();
        $produto = $order->items->first()->product_id;
        $link = PedidoDeAvaliacao::link($order);

        $this->from($link)->post($link, ['avaliacoes' => [$produto => ['comentario' => 'Sem estrela']]])->assertSessionHasErrors('avaliacoes');
        $this->assertSame(0, Review::query()->count());
    }

    public function test_link_vencido_ou_adulterado_e_recusado(): void
    {
        $order = $this->pedido();
        $outro = $this->pedido(['shipping_email' => 'outro@exemplo.com']);
        $produto = $order->items->first()->product_id;

        $vencido = PedidoDeAvaliacao::link($order, now()->subMinute());
        $this->get($vencido)->assertForbidden();
        $this->post($vencido, ['avaliacoes' => [$produto => ['nota' => 5]]])->assertForbidden();

        // Trocar o número do pedido no link quebra a assinatura.
        $adulterado = str_replace('/avaliar-pedido/'.$order->id.'?', '/avaliar-pedido/'.$outro->id.'?', PedidoDeAvaliacao::link($order));
        $this->get($adulterado)->assertForbidden();

        $semAssinatura = Str::before(PedidoDeAvaliacao::link($order), '?');
        $this->post($semAssinatura, ['avaliacoes' => [$produto => ['nota' => 5]]])->assertForbidden();

        $this->assertSame(0, Review::query()->count());
    }

    public function test_pedido_cancelado_depois_nao_avalia(): void
    {
        $order = $this->pedido();
        $produto = $order->items->first()->product_id;
        $link = PedidoDeAvaliacao::link($order);
        DB::table('orders')->where('id', $order->id)->update(['status' => Order::STATUS_CANCELLED]);

        $this->get($link)->assertOk()->assertSee('não pode mais ser avaliado');
        $this->post($link, ['avaliacoes' => [$produto => ['nota' => 5]]])->assertRedirect();
        $this->assertSame(0, Review::query()->count());
    }
}
