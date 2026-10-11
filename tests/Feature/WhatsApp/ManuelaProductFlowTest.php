<?php

namespace Tests\Feature\WhatsApp;

use App\Models\MercadoLivreToken;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaProductSearch;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fluxo de venda da Manuela (pedido 2026-10-08): cliente fala do produto,
 * ela busca no Kazakora, depois na Shopee/ML, confirma qual é, pergunta a
 * dúvida e responde só pela ficha do produto.
 */
class ManuelaProductFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.manuela_url' => null,
            'services.gemini.api_key' => 'gemini-key',
            'services.gemini.chat_model' => 'gemini-test',
            'services.gemini.fallback_models' => [],
            'services.gemini.retry_delay_ms' => 0,
        ]);

        app(WhatsAppSettings::class)->setMany(['enabled' => true, 'auto_reply_enabled' => true]);
    }

    private function inbound(string $id, string $text): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'contacts' => [['wa_id' => '5511999990000', 'profile' => ['name' => 'José']]],
                        'messages' => [[
                            'from' => '5511999990000',
                            'id' => $id,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function geminiCall(string $name, array $args): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [
            ['functionCall' => ['name' => $name, 'args' => $args, 'id' => 'call_'.$name], 'thoughtSignature' => 'assinatura-'.$name],
        ]]]]];
    }

    private function text(string $text): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]]];
    }

    /** @return array<int, array<string, mixed>> */
    private function geminiRequests(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'generativelanguage'))
            ->map(fn ($pair) => $pair[0]->data())
            ->values()
            ->all();
    }

    private function functionResponse(array $request): array
    {
        return end($request['contents'])['parts'][0]['functionResponse'];
    }

    public function test_acha_no_kazakora_mesmo_sem_estoque_e_pergunta_se_e_aquele(): void
    {
        Product::factory()->create(['name' => 'Webcam Full HD 1080p 2MP USB Plug and Play com Microfone Embutido', 'slug' => 'webcam-full-hd', 'price' => 89.9, 'discount_percentage' => 0, 'discount_amount' => 0, 'stock' => 0, 'is_active' => true]);
        Product::factory()->create(['name' => 'Lixeira Redonda 3L Aço Inox', 'slug' => 'lixeira', 'stock' => 5, 'is_active' => true]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiCall('buscar_produto', ['termo' => 'webcam']))
                ->push($this->text('É a Webcam Full HD 1080p, José? Quer mais informações sobre ela?')),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.IN1', 'Vi uma webcam de vocês no Instagram'))->assertOk();

        $this->assertSame(['É a Webcam Full HD 1080p, José? Quer mais informações sobre ela?'], WhatsAppMessage::query()->where('direction', 'outbound')->pluck('body')->all());

        [, $second] = $this->geminiRequests();
        // A volta da ferramenta leva a assinatura do Gemini de volta, intacta.
        $this->assertSame('assinatura-buscar_produto', $second['contents'][1]['parts'][0]['thoughtSignature']);
        $response = $this->functionResponse($second);
        $this->assertSame(['call_buscar_produto', 'site da KazaKora'], [$response['id'], $response['response']['onde']]);
        $this->assertCount(1, $response['response']['encontrados']);
        $this->assertSame('Webcam Full HD 1080p 2MP USB Plug and Play com Microfone Embutido', $response['response']['encontrados'][0]['nome']);
        $this->assertFalse($response['response']['encontrados'][0]['em_estoque']);
        $this->assertSame('https://kazakora.devlira.com.br/produtos/webcam-full-hd', $response['response']['encontrados'][0]['link']);
    }

    public function test_cliente_confirma_e_a_ficha_fica_na_conversa_pras_proximas_duvidas(): void
    {
        $product = Product::factory()->create(['name' => 'Webcam Full HD 1080p', 'slug' => 'webcam-full-hd', 'description' => '<p>Resolução 1920x1080 a 30fps.</p><p>Microfone embutido.</p>', 'stock' => 4, 'is_active' => true]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiCall('abrir_produto', ['origem' => 'kazakora', 'id' => (string) $product->id]))
                ->push($this->text('Ótimo! Qual sua dúvida sobre ela?'))
                ->push($this->text('Ela grava em 1920x1080 a 30fps, ou seja, Full HD de verdade.')),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.IN1', 'Sim, é essa'))->assertOk();
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.IN2', 'Ela é 1080 mesmo?'))->assertOk();

        $ficha = WhatsAppConversation::query()->sole()->metadata[ManuelaAgentClient::PRODUCT_CONTEXT_KEY];
        $this->assertSame("Resolução 1920x1080 a 30fps.\nMicrofone embutido.", $ficha['descricao']);

        $third = $this->geminiRequests()[2];
        $this->assertStringContainsString('Resolução 1920x1080 a 30fps.', $third['systemInstruction']['parts'][0]['text']);
    }

    public function test_sem_produto_no_site_avisa_o_cliente_e_busca_no_mercado_livre(): void
    {
        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(), 'ml_user_id' => 123456789, 'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'valid-access-token', 'refresh_token' => 'valid-refresh-token',
            'token_expires_at' => now()->addHours(6), 'scopes' => ['offline_access', 'read', 'write'],
        ]);
        MarketplaceAccount::query()->create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456789', 'connected_at' => now(),
        ]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiCall('buscar_produto', ['termo' => 'ring light']))
                ->push($this->text('Achei! É o Ring Light 26cm com tripé? Quer mais informações?')),
            'https://api.mercadolibre.com/users/123456789/items/search*' => Http::response(['results' => ['MLB1', 'MLB2'], 'paging' => ['total' => 2]]),
            'https://api.mercadolibre.com/items?*' => Http::response([
                ['code' => 200, 'body' => ['id' => 'MLB1', 'title' => 'Ring Light 26cm Com Tripé', 'price' => 59.9, 'permalink' => 'https://produto.mercadolivre.com.br/MLB-1']],
                ['code' => 200, 'body' => ['id' => 'MLB2', 'title' => 'Furadeira De Impacto 650w', 'price' => 199, 'permalink' => 'https://produto.mercadolivre.com.br/MLB-2']],
            ]),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.IN1', 'Vocês têm ring light?'))->assertOk();

        $sent = WhatsAppMessage::query()->where('direction', 'outbound')->orderBy('id')->get();
        $this->assertSame([ManuelaAgentClient::SEARCHING_ELSEWHERE_REPLY, 'Achei! É o Ring Light 26cm com tripé? Quer mais informações?'], $sent->pluck('body')->all());
        $this->assertTrue($sent[0]->payload['interim']);

        $response = $this->functionResponse($this->geminiRequests()[1]);
        $this->assertSame([['origem' => 'mercado_livre', 'id' => 'MLB1', 'nome' => 'Ring Light 26cm Com Tripé', 'preco' => 'R$ 59,90', 'link' => 'https://produto.mercadolivre.com.br/MLB-1']], $response['response']['encontrados']);
    }

    public function test_nao_acha_em_lugar_nenhum_e_chama_uma_pessoa(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiCall('buscar_produto', ['termo' => 'drone']))
                ->push($this->text('[HUMANO] Não encontrei esse drone por aqui, José. Vou pedir pra uma pessoa do time verificar pra você.')),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]),
        ]);

        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.IN1', 'Tem drone?'))->assertOk();

        $conversation = WhatsAppConversation::query()->sole();
        $this->assertTrue($conversation->needs_human);
        $this->assertSame([ManuelaAgentClient::SEARCHING_ELSEWHERE_REPLY, 'Não encontrei esse drone por aqui, José. Vou pedir pra uma pessoa do time verificar pra você.'], WhatsAppMessage::query()->where('direction', 'outbound')->orderBy('id')->pluck('body')->all());
    }

    public function test_aviso_de_outro_catalogo_nao_impede_nova_tentativa_quando_o_google_cai(): void
    {
        $busy = ['error' => ['code' => 503, 'message' => 'high demand']];
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($this->geminiCall('buscar_produto', ['termo' => 'drone']))
                ->push($busy, 503)->push($busy, 503)
                ->push($this->text('Não achei o drone, José.')),
            'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]]),
        ]);

        // Nos testes a fila é síncrona: a nova tentativa (que em produção sai
        // 1 minuto depois) roda na hora e não pode ser barrada pelo aviso.
        $this->postJson('/api/whatsapp/webhook', $this->inbound('wamid.IN1', 'Tem drone?'))->assertOk();

        $this->assertSame([ManuelaAgentClient::SEARCHING_ELSEWHERE_REPLY, 'Não achei o drone, José.'], WhatsAppMessage::query()->where('direction', 'outbound')->orderBy('id')->pluck('body')->all());
    }

    public function test_busca_por_similaridade_do_nome(): void
    {
        Product::factory()->create(['name' => 'Caixa Sanfonada de Ferramentas Profissional 85 Peças', 'slug' => 'caixa-85', 'is_active' => true]);
        Product::factory()->create(['name' => 'Caixa Sanfonada de Ferramentas Profissional 168 Peças', 'slug' => 'caixa-168', 'is_active' => true]);
        Product::factory()->create(['name' => 'Webcam Full HD 1080p', 'slug' => 'webcam', 'is_active' => true]);
        Product::factory()->create(['name' => 'Webcam Antiga', 'slug' => 'webcam-antiga', 'is_active' => false]);

        $search = app(ManuelaProductSearch::class);

        $this->assertSame(['Caixa Sanfonada de Ferramentas Profissional 168 Peças', 'Caixa Sanfonada de Ferramentas Profissional 85 Peças'], array_column($search->searchStore('caixa de ferramenta 168 peças'), 'nome'));
        $this->assertSame(['Webcam Full HD 1080p'], array_column($search->searchStore('web cam'), 'nome'));
        $this->assertSame([], $search->searchStore('drone'));
        $this->assertSame([], $search->searchStore('168'));
    }
}
