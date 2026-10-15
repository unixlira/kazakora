<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Descrição e conteúdo pelo Gemini a partir das FOTOS (pedido 2026-10-14),
 * igual nas lojas do grupo: KazaKora (botão do admin e geração sem
 * descrição) e FerraKora (conteúdo na API e comando em lote com cota).
 */
class DescricaoPelasFotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.api_key' => 'chave-de-teste',
            'services.gemini.chat_model' => 'gemini-teste',
            'services.gemini.fallback_models' => [],
            'services.gemini.retry_delay_ms' => 0,
        ]);
        Storage::fake('public');
    }

    private function produto(?string $descricao = null): Product
    {
        $product = Product::factory()->create(['name' => 'Jogo de Soquetes 12 Peças', 'description' => $descricao, 'is_active' => true]);

        foreach ([0, 1, 2] as $i) {
            Storage::disk('public')->put("products/x/{$i}.jpg", "\xFF\xD8\xFFfoto{$i}");
            $product->images()->create(['path' => "products/x/{$i}.jpg", 'position' => $i, 'is_primary' => $i === 0]);
        }

        return $product->fresh();
    }

    private function respostaGemini(bool $comDescricao = false): array
    {
        $json = [
            'destaques' => ['Catraca reversível', 'Estojo organizador', '12 peças'],
            'chamada' => 'Tudo para apertar e soltar num estojo só',
            'blocos' => [
                ['titulo' => 'Catraca que não escapa', 'texto' => 'Reversível e firme.', 'imagem' => 1],
                ['titulo' => 'Estojo que organiza', 'texto' => 'Cada peça no lugar.', 'imagem' => 2],
            ],
            'beneficios' => [['Praticidade', 'tudo junto']],
            'duvidas' => [['Vem com extensão?', 'Sim, aparece na foto.']],
        ];

        if ($comDescricao) {
            $json = ['descricao' => "Jogo completo de soquetes.\n\nCaracterísticas:\n- Catraca reversível\n- Barra de extensão", ...$json];
        }

        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]], 'usageMetadata' => []];
    }

    public function test_sem_descricao_o_gemini_gera_pelas_fotos(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini())]);

        $conteudo = app(AnuncioConteudoService::class)->gerar($this->produto());

        $this->assertSame(ProductAdContent::FONTE_GEMINI, $conteudo->fonte);
        $this->assertSame('Catraca que não escapa', $conteudo->blocos[0]['titulo']);

        Http::assertSent(function ($request) {
            $partes = $request->data()['contents'][0]['parts'];
            $imagens = collect($partes)->filter(fn ($parte) => isset($parte['inline_data']))->count();

            return $imagens === 3 && str_contains(end($partes)['text'], 'Não há descrição cadastrada');
        });
    }

    public function test_botao_do_admin_devolve_a_descricao_sem_salvar_e_reaproveita_ao_salvar(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini(true))]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = $this->produto('Descrição antiga.');

        $resposta = $this->actingAs($admin)->postJson("/admin/produtos/{$product->id}/descricao-ia")
            ->assertOk()
            ->assertJsonPath('conteudo.blocos.0.titulo', 'Catraca que não escapa');

        $descricao = $resposta->json('descricao');
        $this->assertStringContainsString('- Catraca reversível', $descricao);
        $this->assertSame('Descrição antiga.', $product->fresh()->description, 'nada é salvo antes da revisão');
        $this->assertNull($product->fresh()->adContent);

        // Salvou a descrição sugerida sem mudar: o conteúdo já gerado é usado, sem nova chamada.
        $product->update(['description' => $descricao]);
        $conteudo = app(AnuncioConteudoService::class)->gerar($product->fresh());

        $this->assertSame(ProductAdContent::FONTE_GEMINI, $conteudo->fonte);
        $this->assertSame('Estojo que organiza', $conteudo->blocos[1]['titulo']);
        Http::assertSentCount(1);
    }

    public function test_botao_do_admin_sem_fotos_explica(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->postJson("/admin/produtos/{$product->id}/descricao-ia")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cadastre pelo menos uma foto antes de gerar a descrição.');
    }

    public function test_botao_do_admin_sem_cota_responde_503(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson("/admin/produtos/{$this->produto()->id}/descricao-ia")->assertStatus(503);
    }

    public function test_api_do_ferrakora_manda_o_conteudo_na_pagina_do_produto(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini())]);
        $product = $this->produto('Jogo de soquetes.');
        FerraKoraProduto::query()->create(['product_id' => $product->id, 'ordem' => 1, 'ativo' => true]);
        app(AnuncioConteudoService::class)->gerar($product);

        $this->getJson("/api/ferrakora/produtos/kz-{$product->id}")
            ->assertOk()
            ->assertJsonPath('product.conteudo.fonte', 'gemini')
            ->assertJsonPath('product.conteudo.blocos.0.imagem', 1)
            ->assertJsonPath('product.conteudo.blocos.0.imagem_url', $product->images[1]->url)
            ->assertJsonPath('product.conteudo.destaques.0', 'Catraca reversível');

        $this->assertArrayNotHasKey('conteudo', $this->getJson('/api/ferrakora/produtos')->json('products.0'));
    }

    public function test_comando_do_ferrakora_dry_run_nao_chama_o_gemini(): void
    {
        Http::fake();
        $product = $this->produto('Jogo de soquetes.');
        FerraKoraProduto::query()->create(['product_id' => $product->id, 'ordem' => 1, 'ativo' => true]);

        $this->artisan('ferrakora:gerar-conteudo', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] #'.$product->id)
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, ProductAdContent::count());
    }

    public function test_comando_do_ferrakora_espera_e_tenta_de_novo_quando_falta_cota(): void
    {
        Http::fakeSequence('generativelanguage.googleapis.com/*')
            ->push(['error' => 'quota'], 429)
            ->push(['error' => 'quota'], 429)
            ->push($this->respostaGemini());

        $product = $this->produto('Jogo de soquetes.');
        $fora = $this->produto('Outro.');
        FerraKoraProduto::query()->create(['product_id' => $product->id, 'ordem' => 1, 'ativo' => true]);
        FerraKoraProduto::query()->create(['product_id' => $fora->id, 'ordem' => 2, 'ativo' => false]);

        $this->artisan('ferrakora:gerar-conteudo', ['--pausa' => 0, '--limit' => 5])->assertSuccessful();

        $this->assertSame(ProductAdContent::FONTE_GEMINI, $product->fresh()->adContent->fonte);
        $this->assertNull($fora->fresh()->adContent, 'produto inativo no FerraKora fica de fora');
    }

    public function test_comando_do_ferrakora_para_sem_gravar_regra_automatica_se_a_cota_nao_volta(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);
        $product = $this->produto('Jogo de soquetes.');
        FerraKoraProduto::query()->create(['product_id' => $product->id, 'ordem' => 1, 'ativo' => true]);

        $this->artisan('ferrakora:gerar-conteudo', ['--pausa' => 0, '--tentativas' => 2])->assertFailed();

        $this->assertNull($product->fresh()->adContent);
    }
}
