<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** POST /api/tiktok/reports/import (2026-10-11). */
class TikTokReportsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.tiktok_reports.token' => 'segredo-de-teste']);
    }

    public function test_sem_token_ou_token_errado_recusa(): void
    {
        $this->postJson('/api/tiktok/reports/import')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer errado')->postJson('/api/tiktok/reports/import')->assertStatus(401);
    }

    public function test_recebe_arquivo_e_nao_duplica(): void
    {
        $arquivo = fn () => UploadedFile::fake()->createWithContent('Income_20261001.xlsx', 'conteudo-do-excel');

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/tiktok/reports/import', ['file' => $arquivo()])
            ->assertOk()
            ->assertJsonPath('arquivos.0.duplicado', false);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/tiktok/reports/import', ['file' => $arquivo()])
            ->assertOk()
            ->assertJsonPath('arquivos.0.duplicado', true);

        $this->assertDatabaseCount('tiktok_report_uploads', 1);
        $this->assertDatabaseHas('tiktok_report_uploads', ['nome_original' => 'Income_20261001.xlsx', 'tipo' => 'liquidacao']);
    }

    public function test_aceita_arquivo_cru_no_corpo(): void
    {
        $this->call('POST', '/api/tiktok/reports/import', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer segredo-de-teste',
            'HTTP_X_FILE_NAME' => 'orders.csv',
            'CONTENT_TYPE' => 'text/csv',
        ], "pedido,valor\n1,10")->assertOk();

        $this->assertDatabaseHas('tiktok_report_uploads', ['nome_original' => 'orders.csv', 'tipo' => 'pedidos']);
    }

    public function test_recusa_extensao_estranha_e_avisa_em_vermelho(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/tiktok/reports/import', ['file' => UploadedFile::fake()->createWithContent('virus.exe', 'x')])
            ->assertStatus(422);

        \Illuminate\Support\Facades\Notification::assertSentTo(\App\Models\User::first(), \App\Notifications\TikTokRelatorioNotification::class,
            fn ($n, $canais, $quem) => $n->toArray($quem)['nivel'] === 'erro' && $n->toArray($quem)['message'] === 'Erro importação arquivo financeiro TikTok');
    }

    public function test_importou_avisa_em_verde(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/tiktok/reports/import', ['file' => UploadedFile::fake()->createWithContent('Income.xlsx', 'abc')])
            ->assertOk();

        \Illuminate\Support\Facades\Notification::assertSentTo(\App\Models\User::first(), \App\Notifications\TikTokRelatorioNotification::class,
            fn ($n, $canais, $quem) => $n->toArray($quem)['nivel'] === 'sucesso');
    }

    public function test_admin_envia_pela_tela(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/arquivos-tiktok')->assertOk();
        $this->actingAs($admin)->post('/admin/arquivos-tiktok', ['arquivos' => [UploadedFile::fake()->createWithContent('Income_out.xlsx', 'dados')]])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('tiktok_report_uploads', ['nome_original' => 'Income_out.xlsx', 'ip' => null]);
    }
}
