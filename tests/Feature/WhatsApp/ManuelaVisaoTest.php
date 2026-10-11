<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\Catalog\Models\Product;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\ManuelaVisao;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Achado real 2026-10-11: foto do kit de tarraxas (R$ 90) respondida com o
 * preço da bike falada antes (R$ 562,85).
 */
class ManuelaVisaoTest extends TestCase
{
    use RefreshDatabase;

    private Product $bike;

    private Product $tarraxa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bike = Product::factory()->create(['name' => 'Bike Spinning Ergométrica 150kg', 'price' => 562.85, 'is_active' => true]);
        $this->tarraxa = Product::factory()->create(['name' => 'Kit Macho E Tarracha Jogo 20 Peças', 'price' => 90, 'is_active' => true]);
    }

    private function gemini(string $resposta): void
    {
        $g = Mockery::mock(GeminiClient::class);
        $g->shouldReceive('isConfigured')->andReturn(true);
        $g->shouldReceive('generate')->andReturn($resposta);
        $this->app->instance(GeminiClient::class, $g);
    }

    public function test_so_aceita_produto_com_confianca_alta_e_que_existe(): void
    {
        $this->gemini('{"descricao":"kit de tarraxas","produto_id":'.$this->tarraxa->id.',"confianca":0.95}');
        $this->assertSame($this->tarraxa->id, app(ManuelaVisao::class)->identificar('img', 'image/jpeg')['produto_id']);

        $this->gemini('{"descricao":"ferramenta","produto_id":'.$this->tarraxa->id.',"confianca":0.5}');
        $this->assertNull(app(ManuelaVisao::class)->identificar('img', 'image/jpeg')['produto_id']);

        $this->gemini('{"descricao":"x","produto_id":999999,"confianca":0.99}');
        $this->assertNull(app(ManuelaVisao::class)->identificar('img', 'image/jpeg')['produto_id']);
    }

    private function rodarFoto(?int $identificado): WhatsAppConversation
    {
        $conversa = WhatsAppConversation::create([
            'wa_id' => '5511999990000', 'phone' => '5511999990000', 'ai_enabled' => true,
            'metadata' => [ManuelaAgentClient::PRODUCT_CONTEXT_KEY => ['nome' => $this->bike->name, 'preco' => 'R$ 562,85', 'aberto_em' => now()->toIso8601String()]],
        ]);
        $foto = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'image', 'body' => 'ainda tem em estoque esse item?', 'status' => 'received', 'payload' => ['media_id' => 'm1']]);

        $g = Mockery::mock(GeminiClient::class);
        $g->shouldReceive('isConfigured')->andReturn(true);
        $this->app->instance(GeminiClient::class, $g);

        $visao = Mockery::mock(ManuelaVisao::class);
        $visao->shouldReceive('identificar')->andReturn(['descricao' => 'kit de tarraxas', 'produto_id' => $identificado, 'nome' => $identificado ? $this->tarraxa->name : null, 'confianca' => $identificado ? 0.95 : 0.3]);
        $this->app->instance(ManuelaVisao::class, $visao);

        $media = Mockery::mock(WhatsAppMediaDownloader::class);
        $media->shouldReceive('download')->andReturn(['body' => 'jpg', 'mime_type' => 'image/jpeg']);

        $manuela = Mockery::mock(ManuelaAutoReplyService::class);
        $manuela->shouldReceive('buildReply')->andReturn(['reply' => '', 'source' => 'ia']);
        $agent = Mockery::mock(ManuelaAgentClient::class);
        $agent->shouldReceive('isConfigured')->andReturn(true);

        (new ReplyWithManuela($conversa->id, $foto->id))->handle($manuela, Mockery::mock(WhatsAppOutbox::class), $agent, $g, $media);

        return $conversa->fresh();
    }

    public function test_foto_identificada_vira_o_produto_em_conversa(): void
    {
        $conversa = $this->rodarFoto($this->tarraxa->id);

        $this->assertStringContainsString('Tarracha', ManuelaAgentClient::productInContext($conversa)['nome']);
    }

    public function test_foto_nao_identificada_tira_o_produto_anterior(): void
    {
        $conversa = $this->rodarFoto(null);

        $this->assertNull(ManuelaAgentClient::productInContext($conversa), 'a bike não pode continuar como produto em conversa');
    }
}
