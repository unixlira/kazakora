<?php

namespace Tests\Feature\Marketplace;

use App\Models\MercadoLivreToken;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Drivers\MercadoLivreDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\ReviewImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MercadoLivreFetchReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function conectar(): void
    {
        MercadoLivreToken::query()->create([
            'id' => (string) Str::uuid(),
            'ml_user_id' => 654321,
            'ml_nickname' => 'LOJA_KAZAKORA',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(6),
            'scopes' => ['offline_access', 'read', 'write'],
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '654321',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);
    }

    private function resposta(): array
    {
        return [
            'paging' => ['total' => 3, 'limit' => 100, 'offset' => 0],
            'reviews' => [
                ['id' => 111, 'status' => 'published', 'rate' => 5, 'content' => ' Funcionou bem. ', 'date_created' => '2026-09-16T03:50:27Z', 'media' => [
                    ['type' => 'photo', 'variations' => [['size' => '748x1200', 'url' => 'http://http2.mlstatic.com/a-F.jpg']]],
                ]],
                ['id' => 222, 'status' => 'published', 'rate' => 2, 'content' => '', 'date_created' => '2026-09-10T10:00:00Z', 'media' => []],
                ['id' => 333, 'status' => 'hidden', 'rate' => 1, 'content' => 'oculta', 'date_created' => '2026-09-01T10:00:00Z', 'media' => []],
            ],
        ];
    }

    public function test_fetch_reviews_mapeia_nota_comentario_foto_e_ignora_nao_publicada(): void
    {
        $this->conectar();
        Http::fake(['*/reviews/item/MLB1*' => Http::response($this->resposta())]);

        $reviews = app(MercadoLivreDriver::class)->fetchReviews('MLB1');

        $this->assertCount(2, $reviews);
        $this->assertSame('111', $reviews[0]['external_id']);
        $this->assertSame(5, $reviews[0]['rating']);
        $this->assertSame('Funcionou bem.', $reviews[0]['comment']);
        $this->assertSame(['https://http2.mlstatic.com/a-F.jpg'], $reviews[0]['images']);
        $this->assertSame('Cliente Mercado Livre', $reviews[0]['reviewer_name']);
        $this->assertSame([], $reviews[0]['model_ids']);
        $this->assertNull($reviews[1]['comment']);
    }

    public function test_review_import_service_grava_avaliacoes_do_ml_sem_duplicar(): void
    {
        $this->conectar();
        Http::fake(['*/reviews/item/MLB1*' => Http::response($this->resposta())]);

        $produto = Product::factory()->create();
        ProductChannelListing::create([
            'product_id' => $produto->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'external_id' => 'MLB1',
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
        ]);

        app(ReviewImportService::class)->importAll();
        $resumo = app(ReviewImportService::class)->importAll();

        $this->assertSame(2, Review::query()->where('channel', 'mercado_livre')->count());
        $this->assertSame(0, $resumo['imported']);
    }
}
