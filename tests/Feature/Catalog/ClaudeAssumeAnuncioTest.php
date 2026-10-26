<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\AnuncioClaudePedido;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-10-25: "se o Gemini não responder, o Claude assume a
 * geração do texto", no botão e no lote automático. O pedido vai para a fila
 * (anuncio_claude_pedidos) e o worker do PC devolve pelo artisan.
 */
class ClaudeAssumeAnuncioTest extends TestCase
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
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);
    }

    private function produto(?string $descricao = "Jogo de soquetes.\n\nUso:\nAperta e solta parafusos."): Product
    {
        $product = Product::factory()->create(['name' => 'Jogo de Soquetes 12 Peças', 'description' => $descricao, 'is_active' => true]);

        foreach ([0, 1] as $i) {
            Storage::disk('public')->put("products/x/{$i}.jpg", "\xFF\xD8\xFFfoto{$i}");
            $product->images()->create(['path' => "products/x/{$i}.jpg", 'position' => $i, 'is_primary' => $i === 0]);
        }

        return $product->fresh();
    }

    private function jsonClaude(bool $comDescricao = false): string
    {
        $json = [
            'destaques' => ['Catraca reversível', 'Estojo organizador', '12 peças'],
            'chamada' => 'Tudo num estojo só',
            'blocos' => [
                ['titulo' => 'Catraca que não escapa', 'texto' => 'Reversível e firme.', 'imagem' => 1],
                ['titulo' => 'Estojo que organiza', 'texto' => 'Cada peça no lugar.', 'imagem' => 0],
            ],
            'beneficios' => [['Praticidade', 'tudo junto']],
            'duvidas' => [['Vem com extensão?', 'Sim.']],
            'cta' => ['titulo' => 'Seu kit completo', 'texto' => 'Aperte qualquer parafuso hoje.', 'garantias' => ['Frete grátis'], 'botao' => 'Quero o meu'],
        ];

        if ($comDescricao) {
            $json = ['descricao' => "Jogo completo de soquetes.\n\n✅ Catraca reversível", ...$json];
        }

        return "Aqui está:\n".json_encode($json, JSON_UNESCAPED_UNICODE);
    }

    private function pegarDaFila(): array
    {
        $this->artisan('anuncio-claude:proxima')->assertSuccessful();

        return AnuncioClaudePedido::query()->latest('id')->first()->toArray();
    }

    public function test_gemini_sem_cota_guarda_a_regra_automatica_e_o_claude_reescreve(): void
    {
        $product = $this->produto();

        $conteudo = app(AnuncioConteudoService::class)->gerar($product);

        $this->assertSame(ProductAdContent::FONTE_AUTOMATICO, $conteudo->fonte, 'a página não fica sem conteúdo');
        $this->assertStringContainsString('Claude na fila', $conteudo->erro);
        $pedido = AnuncioClaudePedido::query()->sole();
        $this->assertSame(AnuncioClaudePedido::TIPO_CONTEUDO, $pedido->tipo);
        $this->assertStringContainsString('Jogo de Soquetes 12 Peças', $pedido->prompt);
        $this->assertCount(2, $pedido->imagens);

        // Gerar de novo com o pedido em aberto não duplica.
        app(AnuncioConteudoService::class)->gerar($product->fresh(), forcar: true);
        $this->assertSame(1, AnuncioClaudePedido::count());

        $this->pegarDaFila();
        $pedido->refresh();
        $this->assertSame(AnuncioClaudePedido::STATUS_GERANDO, $pedido->status);

        app(AnuncioConteudoService::class)->aplicarRespostaClaude($pedido, $this->jsonClaude());

        $conteudo = $product->fresh()->adContent;
        $this->assertSame(ProductAdContent::FONTE_CLAUDE, $conteudo->fonte);
        $this->assertSame('Catraca que não escapa', $conteudo->blocos[0]['titulo']);
        $this->assertNull($conteudo->erro);
        $this->assertSame('🛒 Quero o meu', $conteudo->cta['botao'], 'mesma conferência do Gemini');
        $this->assertSame(AnuncioClaudePedido::STATUS_PRONTO, $pedido->fresh()->status);
        $this->assertFalse(app(AnuncioConteudoService::class)->precisaGerar($product->fresh()));
    }

    public function test_resposta_de_produto_que_mudou_nao_sobrescreve(): void
    {
        $product = $this->produto();
        app(AnuncioConteudoService::class)->gerar($product);
        $pedido = AnuncioClaudePedido::query()->sole();
        $product->update(['name' => 'Outro nome']);

        app(AnuncioConteudoService::class)->aplicarRespostaClaude($pedido, $this->jsonClaude());

        $this->assertSame(ProductAdContent::FONTE_AUTOMATICO, $product->fresh()->adContent->fonte);
        $this->assertStringContainsString('Não aplicado', $pedido->fresh()->erro);
    }

    public function test_botao_da_descricao_pelas_fotos_espera_o_claude(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = $this->produto('Descrição antiga.');

        $id = $this->actingAs($admin)->postJson("/admin/produtos/{$product->id}/descricao-ia")->assertStatus(202)->json('claude');
        $this->actingAs($admin)->getJson("/admin/produtos/{$product->id}/descricao-ia/{$id}")->assertOk()->assertJsonPath('status', 'pendente');

        $this->pegarDaFila();
        $this->actingAs($admin)->getJson("/admin/produtos/{$product->id}/descricao-ia/{$id}")->assertJsonPath('status', 'gerando');
    }

    public function test_status_pronto_devolve_descricao_e_reaproveita_ao_salvar(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = $this->produto('Descrição antiga.');
        $id = $this->actingAs($admin)->postJson("/admin/produtos/{$product->id}/descricao-ia")->json('claude');
        $this->pegarDaFila();
        app(AnuncioConteudoService::class)->aplicarRespostaClaude(AnuncioClaudePedido::find($id), $this->jsonClaude(true));

        $resposta = $this->actingAs($admin)->getJson("/admin/produtos/{$product->id}/descricao-ia/{$id}")
            ->assertOk()
            ->assertJsonPath('status', 'pronto')
            ->assertJsonPath('conteudo.blocos.0.titulo', 'Catraca que não escapa');
        $this->assertSame('Descrição antiga.', $product->fresh()->description, 'nada é salvo antes da revisão');

        $product->update(['description' => $resposta->json('descricao')]);
        $conteudo = app(AnuncioConteudoService::class)->gerar($product->fresh());
        $this->assertSame('Estojo que organiza', $conteudo->blocos[1]['titulo'], 'prévia usada sem nova chamada');
    }

    public function test_lote_manda_o_cta_para_o_claude_e_nao_insiste_no_gemini(): void
    {
        $product = $this->produto();
        ProductAdContent::query()->create([
            'product_id' => $product->id, 'fonte' => ProductAdContent::FONTE_GEMINI, 'origem_hash' => app(AnuncioConteudoService::class)->hash($product),
            'blocos' => [['titulo' => 'A', 'texto' => 'B', 'imagem' => null]], 'duvidas' => [['Funciona?', 'Sim.']], 'gerado_em' => now(),
        ]);

        $this->artisan('produtos:gerar-conteudo-anuncio')->expectsOutputToContain('na fila do Claude')->assertSuccessful();
        $enviados = count(Http::recorded());
        $this->artisan('produtos:gerar-conteudo-anuncio')->assertSuccessful();

        $this->assertSame($enviados, count(Http::recorded()), 'CTA na fila: o Gemini não é chamado de novo');
        $pedido = AnuncioClaudePedido::query()->sole();
        $this->assertSame(AnuncioClaudePedido::TIPO_CTA, $pedido->tipo);
        $this->assertStringContainsString('Funciona?', $pedido->prompt);

        $this->pegarDaFila();
        app(AnuncioConteudoService::class)->aplicarRespostaClaude($pedido->fresh(), '{"cta": {"titulo": "Garanta já", "texto": "Seu dia mais fácil.", "garantias": ["Compra segura"], "botao": "Comprar"}}');

        $this->assertSame('✨', mb_substr($product->fresh()->adContent->cta['titulo'], -1));
    }

    public function test_resposta_ruim_marca_falhou(): void
    {
        app(AnuncioConteudoService::class)->gerar($this->produto());
        $pedido = AnuncioClaudePedido::query()->sole();
        $this->pegarDaFila();

        $this->artisan('anuncio-claude:responder', ['pedido' => $pedido->id])->assertFailed();

        $this->assertSame(AnuncioClaudePedido::STATUS_FALHOU, $pedido->fresh()->status);
    }

    public function test_proxima_prioriza_o_botao_e_fila_vazia_imprime_chaves(): void
    {
        $this->artisan('anuncio-claude:proxima')->expectsOutput('{}')->assertSuccessful();

        $service = app(AnuncioConteudoService::class);
        $a = $this->produto();
        $service->gerar($a);
        $service->pedirAoClaude($a, AnuncioClaudePedido::TIPO_DESCRICAO);

        $this->pegarDaFila();
        $this->assertSame(AnuncioClaudePedido::STATUS_GERANDO, AnuncioClaudePedido::where('tipo', 'descricao')->value('status'));
        $this->assertSame(AnuncioClaudePedido::STATUS_PENDENTE, AnuncioClaudePedido::where('tipo', 'conteudo')->value('status'));
    }
}
