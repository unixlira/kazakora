<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductIaImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Gerar imagens com IA" na edição do produto (pedido 2026-10-19): 3 a 5
 * imagens na sequência desejo → objeção → CTA, rascunho até aprovar.
 */
class ImagensIaAnuncioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.api_key' => 'chave-de-teste',
            'services.gemini.chat_model' => 'gemini-teste',
            'services.gemini.image_model' => 'gemini-imagem-teste',
            'services.gemini.fallback_models' => [],
            'services.gemini.retry_delay_ms' => 0,
        ]);
        Storage::fake('public');
    }

    private function produto(): Product
    {
        $product = Product::factory()->create(['name' => 'Cesto de Bambu', 'description' => 'Cesto organizador de bambu.']);
        Storage::disk('public')->put('products/x/0.jpg', "\xFF\xD8\xFFfoto");
        $product->images()->create(['path' => 'products/x/0.jpg', 'position' => 0, 'is_primary' => true]);

        return $product->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function plano(int $total): array
    {
        $imagens = collect(range(1, $total))->map(fn ($i) => ['titulo' => "Cena {$i}", 'cena' => "Cesto na sala, cena {$i}."])->all();

        return ['candidates' => [['content' => ['parts' => [['text' => json_encode(['imagens' => $imagens])]]]]], 'usageMetadata' => []];
    }

    private function png(): string
    {
        $imagem = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($imagem);

        return (string) ob_get_clean();
    }

    public function test_planeja_o_lote_na_sequencia_desejo_objecao_cta(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->plano(5))]);
        $product = $this->produto();

        $resposta = $this->actingAs($this->admin())
            ->postJson("/admin/produtos/{$product->id}/imagens-ia", ['quantidade' => 5])
            ->assertOk();

        $this->assertSame(['desejo', 'desejo', 'objecao', 'objecao', 'cta'], array_column($resposta->json('imagens'), 'etapa'));
        $this->assertSame(5, ProductIaImage::query()->where('status', 'pendente')->count());

        Http::assertSent(function ($request) {
            $partes = $request->data()['contents'][0]['parts'];

            return collect($partes)->contains(fn ($parte) => isset($parte['inline_data']))
                && str_contains(end($partes)['text'], 'CTA poderoso');
        });
    }

    public function test_quantidade_fora_de_3_a_5_e_recusada(): void
    {
        $product = $this->produto();

        $this->actingAs($this->admin())->postJson("/admin/produtos/{$product->id}/imagens-ia", ['quantidade' => 6])->assertStatus(422);
        $this->actingAs($this->admin())->postJson("/admin/produtos/{$product->id}/imagens-ia", ['quantidade' => 2])->assertStatus(422);
    }

    public function test_gera_a_imagem_com_a_foto_de_referencia_e_aprova_no_fim_da_galeria(): void
    {
        $product = $this->produto();
        $rascunho = $product->iaImages()->create(['lote' => '01J', 'ordem' => 0, 'etapa' => 'desejo', 'titulo' => 'Sala', 'cena' => 'Cesto na sala.']);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png())]]]]]],
            'usageMetadata' => [],
        ])]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson("/admin/produtos/{$product->id}/imagens-ia/{$rascunho->id}/gerar")
            ->assertOk()
            ->assertJsonPath('imagem.status', 'pronta');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-imagem-teste:generateContent')
            && $request->data()['generationConfig']['responseModalities'] === ['IMAGE']);

        $rascunho->refresh();
        Storage::disk('public')->assertExists($rascunho->path);
        $this->assertSame(1, $product->images()->count(), 'gerar não mexe na galeria');

        $this->actingAs($admin)->postJson("/admin/produtos/{$product->id}/imagens-ia/{$rascunho->id}/aprovar")->assertOk();

        $galeria = $product->images()->orderBy('position')->get();
        $this->assertCount(2, $galeria);
        $this->assertSame($rascunho->path, $galeria[1]->path);
        $this->assertFalse($galeria[1]->is_primary);
        $this->assertSame('aprovada', $rascunho->fresh()->status);
    }

    public function test_plano_gratuito_sem_cota_de_imagem_mostra_o_motivo(): void
    {
        $product = $this->produto();
        $rascunho = $product->iaImages()->create(['lote' => '01J', 'ordem' => 0, 'etapa' => 'cta', 'titulo' => 'Compre', 'cena' => 'Cesto.']);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded for metric: generate_content_free_tier_requests, limit: 0']], 429)]);

        $this->actingAs($this->admin())->postJson("/admin/produtos/{$product->id}/imagens-ia/{$rascunho->id}/gerar")
            ->assertStatus(422)
            ->assertJsonPath('imagem.status', 'erro')
            ->assertJsonFragment(['message' => 'A chave do Gemini está no plano gratuito, que não gera imagens. É preciso ligar o faturamento da API no Google AI Studio.']);
    }

    public function test_descartar_apaga_o_arquivo_e_nao_mexe_na_aprovada(): void
    {
        $product = $this->produto();
        Storage::disk('public')->put('products/x/ia/a.jpg', 'x');
        $rascunho = $product->iaImages()->create(['lote' => '01J', 'ordem' => 0, 'etapa' => 'desejo', 'titulo' => 'Sala', 'cena' => 'Cesto.', 'status' => 'pronta', 'path' => 'products/x/ia/a.jpg']);
        $aprovada = $product->iaImages()->create(['lote' => '01J', 'ordem' => 1, 'etapa' => 'cta', 'titulo' => 'CTA', 'cena' => 'Cesto.', 'status' => 'aprovada']);

        $admin = $this->admin();
        $this->actingAs($admin)->deleteJson("/admin/produtos/{$product->id}/imagens-ia/{$rascunho->id}")->assertOk();
        $this->actingAs($admin)->deleteJson("/admin/produtos/{$product->id}/imagens-ia/{$aprovada->id}")->assertStatus(422);

        Storage::disk('public')->assertMissing('products/x/ia/a.jpg');
        $this->assertModelMissing($rascunho);
        $this->assertModelExists($aprovada);
    }

    public function test_rascunho_de_outro_produto_da_404(): void
    {
        $product = $this->produto();
        $outro = Product::factory()->create();
        $rascunho = $outro->iaImages()->create(['lote' => '01J', 'ordem' => 0, 'etapa' => 'desejo', 'titulo' => 'X', 'cena' => 'Y']);

        $this->actingAs($this->admin())->postJson("/admin/produtos/{$product->id}/imagens-ia/{$rascunho->id}/gerar")->assertNotFound();
    }
}
