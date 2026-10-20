<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lira (2026-10-19): na tela de campanha Meta, um botão pede ao Gemini
 * texto principal, título e descrição, mais 5 opções de cada, a partir do
 * produto. A tela preenche os campos; nada é gravado até salvar.
 */
class MetaAdsTextosComIaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/publicidade/meta-ads/campanhas/textos-ia';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.api_key' => 'chave-de-teste',
            'services.gemini.chat_model' => 'gemini-teste',
            'services.gemini.fallback_models' => [],
            'services.gemini.retry_delay_ms' => 0,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function respostaGemini(array $json): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => "```json\n".json_encode($json)."\n```"]]]]], 'usageMetadata' => []];
    }

    public function test_gera_principal_e_cinco_opcoes_de_cada_pelo_produto(): void
    {
        $produto = Product::factory()->create(['name' => 'Árvore de Natal 1,80 m', 'description' => 'Árvore cheia com 580 galhos e base de metal.', 'is_active' => true]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini([
            'texto_principal' => 'Natal montado em 10 minutos — 580 galhos cheios.',
            'titulo' => 'Árvore cheia de verdade',
            'descricao' => 'Base de metal firme',
            'textos' => ['T1', 'T2', 'T3', 'T4', 'T5', 'T6'],
            'titulos' => ['Árvore cheia de verdade', 'A1', 'A2', 'A2', 'A3', 'A4', 'A5'],
            'descricoes' => ['D1', '', 'D2', 'D3', 'D4', 'D5'],
        ]))]);

        $resposta = $this->actingAs($this->admin())
            ->postJson(self::URL, ['produto_id' => $produto->id, 'campanha' => 'Black November'])
            ->assertOk()
            ->json();

        $this->assertSame('Natal montado em 10 minutos , 580 galhos cheios.', $resposta['texto_principal']);
        $this->assertSame('Árvore cheia de verdade', $resposta['titulo']);
        $this->assertSame('Base de metal firme', $resposta['descricao']);
        $this->assertSame(['T1', 'T2', 'T3', 'T4', 'T5'], $resposta['textos']);
        // Sem repetir o principal nem repetidas.
        $this->assertSame(['A1', 'A2', 'A3', 'A4', 'A5'], $resposta['titulos']);
        $this->assertSame(['D1', 'D2', 'D3', 'D4', 'D5'], $resposta['descricoes']);

        Http::assertSent(function ($request) {
            $prompt = collect($request->data()['contents'][0]['parts'])->pluck('text')->implode("\n");

            return str_contains($prompt, 'Árvore de Natal 1,80 m')
                && str_contains($prompt, '580 galhos')
                && str_contains($prompt, 'Black November');
        });
        $this->assertSame(GeminiUsageLog::PURPOSE_META_ADS, GeminiUsageLog::query()->value('purpose'));
        $this->assertSame(0, MetaAdsCampanha::query()->count());
    }

    public function test_sem_produto_pede_para_escolher(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAs($this->admin())->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.produto_id.0', 'Escolha o produto para a IA escrever os textos.');

        Http::assertNothingSent();
    }

    public function test_resposta_sem_texto_vira_mensagem_para_tentar_de_novo(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini(['titulo' => 'Só título']))]);

        $this->actingAs($this->admin())->postJson(self::URL, ['produto_id' => $produto->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A IA não devolveu o texto principal e o título. Tente de novo.');
    }
}
