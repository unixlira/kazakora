<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Modules\Marketplace\Models\MarketplaceReturnEvidence;
use App\Modules\Marketplace\Support\DuracaoDeVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Evidências das devoluções (pedido do usuário 2026-10-07): foto e vídeo,
 * até 30 MB por arquivo, vídeo de até 1 minuto.
 */
class DevolucoesEvidenciasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MarketplaceReturn $caso;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->caso = MarketplaceReturn::query()->create([
            'channel' => 'tiktok_shop', 'external_id' => 'manual-1', 'external_order_id' => '586', 'kind' => MarketplaceReturn::KIND_DEVOLUCAO,
            'reason_label' => 'Produto com defeito', 'situacao' => MarketplaceReturn::AGUARDANDO_RESPOSTA, 'opened_at' => now(), 'manual' => true,
        ]);
    }

    private function duracaoDoServidor(?float $segundos): void
    {
        $this->app->instance(DuracaoDeVideo::class, new class($segundos) extends DuracaoDeVideo
        {
            public function __construct(private ?float $s) {}

            public function segundos(string $caminho): ?float
            {
                return $this->s;
            }
        });
    }

    public function test_photo_and_video_are_attached_and_listed_on_the_case(): void
    {
        $this->duracaoDoServidor(42.0);

        $this->actingAs($this->admin)->post("/admin/devolucoes/{$this->caso->id}/evidencias", [
            'arquivos' => [UploadedFile::fake()->image('caixa-amassada.jpg'), UploadedFile::fake()->create('abrindo.mp4', 5000, 'video/mp4')],
        ])->assertRedirect()->assertSessionHas('success');

        $evidencias = $this->caso->evidencias()->get();
        $this->assertSame(['foto', 'video'], $evidencias->pluck('tipo')->all());
        $this->assertSame(42, $evidencias[1]->duracao_segundos);
        $evidencias->each(fn ($e) => Storage::disk('local')->assertExists($e->path));
        $this->assertDatabaseHas('marketplace_return_events', ['marketplace_return_id' => $this->caso->id, 'description' => '2 evidências anexadas']);

        $this->actingAs($this->admin)->get('/admin/devolucoes')
            ->assertInertia(fn ($page) => $page->where('casos.0.evidencias.1.tipo', 'video')->where('casos.0.evidencias.1.duracao', 42));
    }

    public function test_video_longer_than_one_minute_is_refused(): void
    {
        $this->duracaoDoServidor(75.0);

        $this->actingAs($this->admin)->post("/admin/devolucoes/{$this->caso->id}/evidencias", [
            'arquivos' => [UploadedFile::fake()->create('longo.mp4', 5000, 'video/mp4')],
        ])->assertSessionHas('error');

        $this->assertSame(0, MarketplaceReturnEvidence::query()->count());
    }

    public function test_without_ffprobe_the_browser_duration_is_used(): void
    {
        $this->duracaoDoServidor(null);

        $this->actingAs($this->admin)->post("/admin/devolucoes/{$this->caso->id}/evidencias", [
            'arquivos' => [UploadedFile::fake()->create('longo.mov', 5000, 'video/quicktime')],
            'duracoes' => [90],
        ])->assertSessionHas('error');

        $this->assertSame(0, MarketplaceReturnEvidence::query()->count());
    }

    public function test_file_over_30_mb_is_refused(): void
    {
        $this->actingAs($this->admin)->post("/admin/devolucoes/{$this->caso->id}/evidencias", [
            'arquivos' => [UploadedFile::fake()->create('enorme.mp4', 31 * 1024, 'video/mp4')],
        ])->assertSessionHasErrors('arquivos.0');

        $this->assertSame(0, MarketplaceReturnEvidence::query()->count());
    }

    public function test_evidence_is_served_privately_and_can_be_removed(): void
    {
        $this->actingAs($this->admin)->post("/admin/devolucoes/{$this->caso->id}/evidencias", [
            'arquivos' => [UploadedFile::fake()->image('foto.png')],
        ]);
        $evidencia = MarketplaceReturnEvidence::query()->firstOrFail();

        $this->get("/admin/devolucoes/{$this->caso->id}/evidencias/{$evidencia->id}")->assertOk();

        auth()->logout();
        $this->get("/admin/devolucoes/{$this->caso->id}/evidencias/{$evidencia->id}")->assertRedirect();

        $this->actingAs($this->admin)->delete("/admin/devolucoes/{$this->caso->id}/evidencias/{$evidencia->id}")->assertSessionHas('success');
        $this->assertModelMissing($evidencia);
        Storage::disk('local')->assertMissing($evidencia->path);
    }
}
