<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Services\VideoDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-29: "Download vídeos" dava 404. A tela tinha sido feita
 * direto no servidor em 2026-08-25 e nunca entrou no git — um deploy
 * seguinte apagou controller, rota e comando, e o agendamento
 * video-downloads:clean passou a falhar de hora em hora.
 */
class VideoDownloadPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_videos_page_opens_for_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/admin/download-videos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/VideoDownloads/Index', false));
    }

    public function test_download_rejects_a_host_outside_the_allowed_list(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post('/admin/download-videos', ['url' => 'https://example.com/video.mp4'])
            ->assertSessionHasErrors('url');
    }

    public function test_scheduled_cleanup_command_exists(): void
    {
        $this->assertSame(0, Artisan::call('video-downloads:clean'));
    }

    public function test_preview_rejects_a_host_outside_the_allowed_list(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/admin/download-videos/preview', ['url' => 'https://example.com/video.mp4'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    // 2026-10-17: TikTok tem de sair sem marca d'água (o formato
    // "download" é o que vem com marca).
    public function test_tiktok_download_never_picks_the_watermarked_format(): void
    {
        $command = (fn (string $url) => $this->downloadCommand($url, '/tmp/x'))
            ->call(app(VideoDownloadService::class), 'https://www.tiktok.com/@loja/video/123');

        $format = $command[array_search('-f', $command, true) + 1];

        $this->assertStringContainsString('[format_id!=download]', $format);
        $this->assertStringNotContainsString('/best', $format);
    }
}
