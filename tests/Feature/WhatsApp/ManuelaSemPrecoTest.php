<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\Catalog\Models\Product;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\ManuelaProductSearch;
use App\Modules\WhatsApp\Support\ManuelaSemPreco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Achado real 2026-10-31: Manuela mandou R$ 537,05 na recuperação do pedido 3305. */
class ManuelaSemPrecoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'k', 'services.whatsapp.manuela_url' => null]);
    }

    public static function comPreco(): array
    {
        return [
            ['A Bike Spinning está saindo por R$ 537,05.'],
            ['Fica r$537'],
            ['Sai por 537,05 no site'],
            ['Custa 537 no Pix'],
            ['São 12x de 44,75'],
            ['Quinhentos e trinta reais'],
            ['Dá 500 reais'],
            ['O preço é 1.200'],
        ];
    }

    public static function semPreco(): array
    {
        return [
            ['Dá uma olhadinha lá no site: https://kazakora.com/produtos/bike-537,05'],
            ['Tem 1,50 m de altura e 0,75 kW'],
            ['A caixa mede 30 x 40 cm e tem 168 peças'],
            ['São avaliações de clientes reais'],
            ['Parcela em até 12x sem juros no site'],
            ['Tem em estoque, sim!'],
        ];
    }

    #[DataProvider('comPreco')]
    public function test_acha_preco(string $texto): void
    {
        $this->assertTrue(ManuelaSemPreco::temPreco($texto));
    }

    #[DataProvider('semPreco')]
    public function test_nao_confunde_medida_com_preco(string $texto): void
    {
        $this->assertFalse(ManuelaSemPreco::temPreco($texto));
    }

    public function test_ficha_e_busca_nao_levam_preco(): void
    {
        $produto = Product::factory()->create(['name' => 'Bike Spinning', 'price' => 537.05, 'stock' => 3, 'is_active' => true]);
        $busca = app(ManuelaProductSearch::class);

        $this->assertArrayNotHasKey('preco', $busca->searchStore('bike')[0]);
        $this->assertArrayNotHasKey('preco', $busca->details(ManuelaProductSearch::ORIGIN_STORE, (string) $produto->id));
    }

    public function test_ficha_antiga_da_conversa_perde_o_preco_e_prompt_traz_a_regra(): void
    {
        $conversa = $this->conversa(['metadata' => [ManuelaAgentClient::PRODUCT_CONTEXT_KEY => ['nome' => 'Bike', 'preco' => 'R$ 537,05', 'aberto_em' => now()->toIso8601String()]]]);
        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt($conversa);

        $this->assertStringNotContainsString('537,05', $prompt);
        $this->assertStringContainsString('NUNCA escreve preço', $prompt);
        $this->assertStringContainsString(ManuelaAgentClient::GERENCIA_TAG, $prompt);
    }

    public function test_resposta_com_preco_nao_sai_e_humano_assume(): void
    {
        $this->assertResposta('Está saindo por R$ 537,05.');
    }

    public function test_marcacao_da_gerencia_chama_humano(): void
    {
        $this->assertResposta(ManuelaAgentClient::GERENCIA_TAG);
    }

    private function assertResposta(string $textoDaIa): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $textoDaIa]]]]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.t']]]),
        ]);
        $conversa = $this->conversa();
        $entrada = $conversa->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Qual o preço?', 'status' => 'received']);

        ReplyWithManuela::dispatchSync($conversa->id, $entrada->id);

        $conversa->refresh();
        $saida = $conversa->messages()->where('direction', 'outbound')->latest('id')->first();
        $this->assertSame(ManuelaSemPreco::RESPOSTA, $saida?->body);
        $this->assertTrue($conversa->needs_human);
        $this->assertFalse($conversa->ai_enabled);
    }

    private function conversa(array $extra = []): WhatsAppConversation
    {
        return WhatsAppConversation::query()->create($extra + ['wa_id' => '5511999990000', 'numero_chave' => '11999990000', 'phone' => '5511999990000', 'status' => 'open', 'ai_enabled' => true]);
    }
}
