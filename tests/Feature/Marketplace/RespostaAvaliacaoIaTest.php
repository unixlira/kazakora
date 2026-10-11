<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\AutoReplyShopeeReviewsService;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Gemini escreve a resposta da avaliação da Shopee (2026-10-11). */
class RespostaAvaliacaoIaTest extends TestCase
{
    use RefreshDatabase;

    private array $enviadas = [];

    private function cenario(?string $textoDaIa, string $revisao = '{"aprovada": true, "motivo": "ok"}'): Review
    {
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn($textoDaIa !== null);
        if ($textoDaIa !== null) {
            // 1ª chamada escreve, 2ª revisa.
            $gemini->shouldReceive('generate')->andReturn($textoDaIa, $revisao);
        }
        $this->app->instance(GeminiClient::class, $gemini);

        $driver = Mockery::mock(\App\Modules\Marketplace\Drivers\MarketplaceChannelDriver::class);
        $driver->shouldReceive('replyReview')->andReturn(['ok' => true]);
        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $produto = Product::factory()->create(['name' => 'Carregador Turbo 25W']);

        return Review::create([
            'product_id' => $produto->id, 'rating' => 5, 'comment' => 'Carrega muito rápido, amei!',
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE, 'external_id' => '123', 'reviewer_name' => 'Ana',
        ]);
    }

    public function test_usa_o_texto_do_gemini(): void
    {
        $review = $this->cenario('Que bom que o carregador turbo está carregando rapidinho! Segue a loja para ver as novidades 💛');

        app(AutoReplyShopeeReviewsService::class)->replyPendingPositiveShopeeReviews(1);

        $review->refresh();
        $this->assertSame('sent', $review->seller_reply_status);
        $this->assertStringContainsString('carregador turbo', $review->seller_reply);
        $this->assertSame('ia_rating_5', $review->seller_reply_template);
    }

    public function test_resposta_terminando_em_emoji_vale(): void
    {
        $review = $this->cenario('Que alegria saber que o carregador chegou antes do prazo! Segue a loja 💛');

        app(AutoReplyShopeeReviewsService::class)->replyPendingPositiveShopeeReviews(1);

        $this->assertSame('ia_rating_5', $review->fresh()->seller_reply_template);
    }

    public function test_texto_fora_das_regras_cai_no_pronto(): void
    {
        $review = $this->cenario('Obrigado! Fale com a gente em https://kazakora.com que fazemos o reembolso.');

        app(AutoReplyShopeeReviewsService::class)->replyPendingPositiveShopeeReviews(1);

        $review->refresh();
        $this->assertSame('sent', $review->seller_reply_status);
        $this->assertStringNotContainsString('http', $review->seller_reply);
        $this->assertStringStartsWith('positive_rating_5_', $review->seller_reply_template);
    }

    public function test_resposta_cortada_no_meio_cai_no_pronto(): void
    {
        $review = $this->cenario('Ficamos muito felizes em saber que o seu carregador turbo chegou dire');

        app(AutoReplyShopeeReviewsService::class)->replyPendingPositiveShopeeReviews(1);

        $this->assertStringStartsWith('positive_rating_5_', $review->fresh()->seller_reply_template);
    }

    public function test_reprovada_pelo_revisor_cai_no_pronto(): void
    {
        $review = $this->cenario('Que bom que gostou do carregador! Segue a loja.', '{"aprovada": false, "motivo": "genérica"}');

        app(AutoReplyShopeeReviewsService::class)->replyPendingPositiveShopeeReviews(1);

        $this->assertStringStartsWith('positive_rating_5_', $review->fresh()->seller_reply_template);
    }

    public function test_revisor_com_resposta_invalida_cai_no_pronto(): void
    {
        $review = $this->cenario('Que bom que gostou do carregador! Segue a loja.', 'acho que está ok');

        app(AutoReplyShopeeReviewsService::class)->replyPendingPositiveShopeeReviews(1);

        $this->assertStringStartsWith('positive_rating_5_', $review->fresh()->seller_reply_template);
    }

    public function test_sem_ia_usa_o_texto_pronto(): void
    {
        $review = $this->cenario(null);

        app(AutoReplyShopeeReviewsService::class)->replyPendingPositiveShopeeReviews(1);

        $this->assertStringStartsWith('positive_rating_5_', $review->fresh()->seller_reply_template);
    }
}
