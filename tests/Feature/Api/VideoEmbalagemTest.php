<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Checkout\Jobs\ConverterVideoEmbalagem;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderPackingVideo;
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
}
