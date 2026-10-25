<?php

namespace Tests\Feature\Console;

use App\Modules\Marketplace\Models\MarketplaceAdPhotoBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Fila do ChatGPT da Cora Box para as fotos de anúncio (2026-10-25): o worker do Homer usa estes comandos. */
class FotosAnuncioChatGptTest extends TestCase
{
    use RefreshDatabase;

    private function pacote(string $status = MarketplaceAdPhotoBrief::STATUS_QUEUED): MarketplaceAdPhotoBrief
    {
        return MarketplaceAdPhotoBrief::create([
            'marketplace' => 'shopee',
            'product_name' => 'Parafusadeira 18V',
            'description' => 'Parafusadeira sem fio.',
            'matrix' => [
                ['key' => 'A', 'title' => 'Capa', 'prompt' => 'Prompt A', 'status' => 'pending', 'image_url' => null],
                ['key' => 'B', 'title' => 'Uso', 'prompt' => 'Prompt B', 'status' => 'pending', 'image_url' => null],
            ],
            'status' => $status,
            'approval_status' => MarketplaceAdPhotoBrief::APPROVAL_APPROVED,
        ]);
    }

    private function png(): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'arte').'.png';
        $imagem = imagecreatetruecolor(20, 20);
        imagepng($imagem, $caminho);

        return $caminho;
    }

    public function test_proxima_sem_fila_devolve_json_vazio(): void
    {
        $this->artisan('fotos-anuncio:proxima')->expectsOutput('{}')->assertSuccessful();
    }

    public function test_proxima_pega_o_mais_antigo_da_fila_e_marca_como_gerando(): void
    {
        $primeiro = $this->pacote();
        $this->pacote();

        $this->artisan('fotos-anuncio:proxima')->assertSuccessful();

        $this->assertSame(MarketplaceAdPhotoBrief::STATUS_GENERATING, $primeiro->fresh()->status);
        $this->assertSame(1, MarketplaceAdPhotoBrief::query()->where('status', MarketplaceAdPhotoBrief::STATUS_QUEUED)->count());
    }

    public function test_proxima_so_lista_as_artes_que_faltam(): void
    {
        Storage::fake('public');
        $brief = $this->pacote();
        $this->artisan('fotos-anuncio:anexar', ['uuid' => $brief->uuid, 'chave' => 'A', 'arquivo' => $this->png()])->assertSuccessful();
        $brief->update(['status' => MarketplaceAdPhotoBrief::STATUS_QUEUED]);

        Artisan::call('fotos-anuncio:proxima');
        $saida = Artisan::output();

        $this->assertStringContainsString('"chave":"B"', $saida);
        $this->assertStringNotContainsString('"chave":"A"', $saida);
    }

    public function test_pacote_gerando_parado_ha_mais_de_3_horas_volta_para_a_fila(): void
    {
        $brief = $this->pacote(MarketplaceAdPhotoBrief::STATUS_GENERATING);
        MarketplaceAdPhotoBrief::query()->whereKey($brief->id)->update(['updated_at' => now()->subHours(4)]);

        $this->assertSame($brief->id, MarketplaceAdPhotoBrief::reivindicarProximo()?->id);
    }

    public function test_anexar_guarda_a_arte_e_conclui_quando_todas_estao_prontas(): void
    {
        Storage::fake('public');
        $brief = $this->pacote(MarketplaceAdPhotoBrief::STATUS_GENERATING);

        $this->artisan('fotos-anuncio:anexar', ['uuid' => $brief->uuid, 'chave' => 'A', 'arquivo' => $this->png()])->assertSuccessful();
        $brief->refresh();
        $this->assertSame(MarketplaceAdPhotoBrief::STATUS_GENERATING, $brief->status);
        $this->assertSame('1/2', $brief->generation_progress_label);
        Storage::disk('public')->assertExists("marketplace-ad-photos/{$brief->uuid}/A.png");

        $this->artisan('fotos-anuncio:anexar', ['uuid' => $brief->uuid, 'chave' => 'B', 'arquivo' => $this->png()])->assertSuccessful();
        $brief->refresh();
        $this->assertSame(MarketplaceAdPhotoBrief::STATUS_COMPLETED, $brief->status);
        $this->assertNotNull($brief->generation_finished_at);
    }

    public function test_anexar_recusa_arquivo_que_nao_e_imagem_e_item_inexistente(): void
    {
        Storage::fake('public');
        $brief = $this->pacote(MarketplaceAdPhotoBrief::STATUS_GENERATING);
        $texto = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($texto, 'não sou imagem');

        $this->artisan('fotos-anuncio:anexar', ['uuid' => $brief->uuid, 'chave' => 'A', 'arquivo' => $texto])->assertFailed();
        $this->artisan('fotos-anuncio:anexar', ['uuid' => $brief->uuid, 'chave' => 'Z', 'arquivo' => $this->png()])->assertFailed();

        $this->assertSame(0, $brief->fresh()->generated_count);
        Storage::disk('public')->assertMissing("marketplace-ad-photos/{$brief->uuid}/Z.png");
    }

    public function test_falha_marca_o_pacote_e_gerar_de_novo_reaproveita_as_artes_prontas(): void
    {
        Storage::fake('public');
        $brief = $this->pacote(MarketplaceAdPhotoBrief::STATUS_GENERATING);
        $this->artisan('fotos-anuncio:anexar', ['uuid' => $brief->uuid, 'chave' => 'A', 'arquivo' => $this->png()])->assertSuccessful();

        $this->artisan('fotos-anuncio:falha', ['uuid' => $brief->uuid, 'mensagem' => 'ChatGPT sem resposta'])->assertSuccessful();
        $brief->refresh();
        $this->assertSame(MarketplaceAdPhotoBrief::STATUS_FAILED, $brief->status);
        $this->assertSame('ChatGPT sem resposta', $brief->last_error);

        $brief->startGeneration();
        $brief->refresh();
        $this->assertSame(MarketplaceAdPhotoBrief::STATUS_QUEUED, $brief->status);
        $this->assertSame(1, $brief->generated_count);
    }
}
