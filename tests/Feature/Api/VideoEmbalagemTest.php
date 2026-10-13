<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\ConverterVideoEmbalagem;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Models\OrderPackingVideo;
use App\Modules\Checkout\Support\RegraVideoEmbalagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Vídeo da embalagem gravado no KoraSync (2026-10-11). */
class VideoEmbalagemTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.print_agent.token' => 'agente']);
        $this->order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SP1',
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01001000', 'shipping_street' => 'R',
            'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP',
            'subtotal' => 10, 'total' => 10,
        ]);
    }

    public function test_koraSync_envia_e_o_video_fica_no_pedido(): void
    {
        Bus::fake([ConverterVideoEmbalagem::class]);

        $r = $this->withHeader('Authorization', 'Bearer agente')
            ->post("/api/print-agent/dashboard/queue/{$this->order->id}/video", [
                'video' => UploadedFile::fake()->create('embalagem.webm', 2048, 'video/webm'),
            ]);
        $r->assertOk()->assertJsonPath('ok', true);

        $video = OrderPackingVideo::first();
        $this->assertSame($this->order->id, $video->order_id);
        $this->assertTrue($video->expira_em->between(now()->addDays(89), now()->addDays(91)));
        Storage::disk('local')->assertExists($video->path);
        Bus::assertDispatched(ConverterVideoEmbalagem::class);
        $this->assertDatabaseHas('order_fulfillment_events', ['order_id' => $this->order->id, 'message' => 'Vídeo da embalagem gravado no KoraSync']);

        // KoraSync assiste.
        $this->withHeader('Authorization', 'Bearer agente')->get("/api/print-agent/dashboard/queue/{$this->order->id}/video")->assertOk();

        // Admin assiste e baixa.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get("/admin/pedidos/{$this->order->id}/videos/{$video->id}")->assertOk();
        $this->actingAs($admin)->get("/admin/pedidos/{$this->order->id}/videos/{$video->id}?baixar=1")->assertDownload("embalagem-pedido-{$this->order->id}.webm");
    }

    public function test_pagina_lista_todos_os_videos_com_filtro(): void
    {
        $outro = $this->order->replicate()->fill(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML9', 'shipping_name' => 'Maria']);
        $outro->save();
        OrderPackingVideo::create(['order_id' => $this->order->id, 'path' => 'embalagem/a.mp4', 'status' => 'pronto', 'tamanho' => 1048576, 'expira_em' => now()->addDays(90)]);
        OrderPackingVideo::create(['order_id' => $outro->id, 'path' => 'embalagem/b.mp4', 'status' => 'pronto', 'tamanho' => 1048576, 'expira_em' => now()->addDays(90)]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/videos-embalagem')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/VideosEmbalagem/Index', false)
                ->has('videos.data', 2)
                ->where('totais.videos', 2)
                ->where('totais.tamanho_mb', 2));

        $this->actingAs($admin)->get('/admin/videos-embalagem?busca=Maria')->assertOk()
            ->assertInertia(fn ($page) => $page->has('videos.data', 1)
                ->where('videos.data.0.pedido.numero', 'ML9')
                ->where('videos.data.0.url', "/admin/pedidos/{$outro->id}/videos/".OrderPackingVideo::where('order_id', $outro->id)->value('id')));

        $this->actingAs($admin)->get('/admin/videos-embalagem?canal=shopee')->assertOk()
            ->assertInertia(fn ($page) => $page->has('videos.data', 1)->where('videos.data.0.pedido.numero', 'SP1'));
    }

    public function test_sem_token_nao_envia(): void
    {
        $this->post("/api/print-agent/dashboard/queue/{$this->order->id}/video", [
            'video' => UploadedFile::fake()->create('embalagem.webm', 10, 'video/webm'),
        ])->assertUnauthorized();
    }

    public function test_limpeza_apaga_os_vencidos(): void
    {
        Storage::disk('local')->put('embalagem/1/velho.mp4', 'x');
        OrderPackingVideo::create(['order_id' => $this->order->id, 'path' => 'embalagem/1/velho.mp4', 'status' => 'pronto', 'expira_em' => now()->subDay()]);

        $this->artisan('videos:limpar-embalagem')->assertSuccessful();

        $this->assertDatabaseCount('order_packing_videos', 0);
        Storage::disk('local')->assertMissing('embalagem/1/velho.mp4');
    }

    public function test_limpeza_com_todos_apaga_tambem_os_que_nao_venceram(): void
    {
        Storage::disk('local')->put('embalagem/1/novo.mp4', 'x');
        OrderPackingVideo::create(['order_id' => $this->order->id, 'path' => 'embalagem/1/novo.mp4', 'status' => 'pronto', 'expira_em' => now()->addDays(80)]);

        $this->artisan('videos:limpar-embalagem')->assertSuccessful();
        $this->assertDatabaseCount('order_packing_videos', 1);

        $this->artisan('videos:limpar-embalagem', ['--todos' => true])->assertSuccessful();
        $this->assertDatabaseCount('order_packing_videos', 0);
        Storage::disk('local')->assertMissing('embalagem/1/novo.mp4');
    }

    /** Lira escolhe na tela o que o KoraSync grava (2026-10-13). */
    public function test_regra_de_gravacao_por_produto_e_categoria_vai_para_a_fila(): void
    {
        $categoria = Category::create(['name' => 'Ferramentas', 'slug' => 'ferramentas']);
        $pai = Product::factory()->create(['category_id' => null]);
        $cor = Product::factory()->create(['parent_product_id' => $pai->id, 'category_id' => null]);
        $daCategoria = Product::factory()->create(['category_id' => $categoria->id]);
        $outro = Product::factory()->create(['category_id' => null]);
        $item = fn (Product $p) => (new OrderItem(['product_id' => $p->id]))->setRelation('product', $p);
        $regra = app(RegraVideoEmbalagem::class);

        // Padrão: não grava nada.
        $this->assertFalse($regra->deveGravar(collect([$item($outro)])));

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->put('/admin/videos-embalagem/regra', [
            'modo' => 'selecionados', 'categorias' => [$categoria->id], 'produtos' => [$pai->id],
        ])->assertRedirect();

        $this->assertTrue($regra->deveGravar(collect([$item($cor)])));
        $this->assertTrue($regra->deveGravar(collect([$item($outro), $item($daCategoria)])));
        $this->assertFalse($regra->deveGravar(collect([$item($outro)])));

        $this->actingAs($admin)->get('/admin/videos-embalagem')->assertOk()
            ->assertInertia(fn ($page) => $page->where('regra.modo', 'selecionados')
                ->where('regra.produtos', [$pai->id])
                ->has('produtos', 3));

        $this->actingAs($admin)->put('/admin/videos-embalagem/regra', ['modo' => 'todos'])->assertRedirect();
        $this->assertTrue($regra->deveGravar(collect([$item($outro)])));

        // A fila do KoraSync leva a decisão no pedido.
        $this->order->items()->create(['product_id' => $outro->id, 'product_name' => 'X', 'quantity' => 1, 'product_price' => 10, 'subtotal' => 10]);
        $fila = $this->withHeader('Authorization', 'Bearer agente')->getJson('/api/print-agent/dashboard/queue')->assertOk()->json();
        $pedido = collect([...$fila['queue'], ...$fila['out_of_stock']])->firstWhere('id', $this->order->id);
        $this->assertTrue($pedido['gravar_video']);
    }

    /**
     * Lira não conseguia ver os vídeos (2026-10-13): saíam MP4 de 1000
     * quadros/s. A conversão fixa 30/s e o reconverter troca o MP4 antigo
     * só quando a conversão nova dá certo.
     */
    public function test_converte_em_30_quadros_e_reconverte_mp4_pronto(): void
    {
        $args = tempnam(sys_get_temp_dir(), 'ffargs');
        $ffmpeg = tempnam(sys_get_temp_dir(), 'ffmpeg');
        // ffmpeg falso: guarda os argumentos e grava "NOVO" no arquivo de saída (último argumento).
        file_put_contents($ffmpeg, "#!/bin/sh\necho \"\$@\" > {$args}\nfor a in \"\$@\"; do s=\"\$a\"; done\nprintf NOVO > \"\$s\"\n");
        chmod($ffmpeg, 0755);
        config(['video-downloads.ffmpeg_binary' => $ffmpeg]);

        Storage::disk('local')->put('embalagem/1/v.mp4', 'ANTIGO');
        $video = OrderPackingVideo::create(['order_id' => $this->order->id, 'path' => 'embalagem/1/v.mp4', 'status' => 'pronto', 'tamanho' => 6, 'expira_em' => now()->addDays(90)]);

        // Sem --executar não mexe em nada.
        Bus::fake([ConverterVideoEmbalagem::class]);
        $this->artisan('embalagem:reconverter')->assertSuccessful();
        Bus::assertNotDispatched(ConverterVideoEmbalagem::class);
        $this->artisan('embalagem:reconverter', ['ids' => [$video->id], '--executar' => true])->assertSuccessful();
        Bus::assertDispatched(ConverterVideoEmbalagem::class, fn ($job) => $job->videoId === $video->id && $job->refazer);

        // Pronto sem refazer: ignora.
        app()->call([new ConverterVideoEmbalagem($video->id), 'handle']);
        Storage::disk('local')->assertExists('embalagem/1/v.mp4');
        $this->assertSame('ANTIGO', Storage::disk('local')->get('embalagem/1/v.mp4'));

        app()->call([new ConverterVideoEmbalagem($video->id, true), 'handle']);
        $this->assertStringContainsString('-r 30', file_get_contents($args));
        $this->assertSame('NOVO', Storage::disk('local')->get('embalagem/1/v.mp4'));
        Storage::disk('local')->assertMissing('embalagem/1/v.mp4.novo.mp4');
        $this->assertSame('pronto', $video->fresh()->status);
        $this->assertSame(4, $video->fresh()->tamanho);

        @unlink($args);
        @unlink($ffmpeg);
    }
}
