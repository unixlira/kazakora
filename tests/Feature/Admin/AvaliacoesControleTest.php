<?php

namespace Tests\Feature\Admin;

use App\Jobs\ContarAvaliacoesCanaisJob;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Models\ContagemAvaliacoesCanal;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\ContagemAvaliacoesCanais;
use App\Services\MercadoLivre\MercadoLivreClient;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Avaliações › Cadastro e controle (pedido do Lira 2026-10-18).
 */
class AvaliacoesControleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function vincular(Product $produto, string $canal, string $id): void
    {
        ProductChannelListing::create(['product_id' => $produto->id, 'channel' => $canal, 'external_id' => $id, 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED]);
    }

    public function test_cadastra_avaliacao_manual_com_foto_e_video(): void
    {
        Storage::fake('public');
        $produto = Product::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin())->post('/admin/avaliacoes/controle', [
            'product_id' => $produto->id,
            'reviewer_name' => 'Maria S.',
            'rating' => 4,
            'comment' => 'Chegou rápido.',
            'data' => '2026-10-10',
            'midias' => [
                UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('video.mp4', 500, 'video/mp4'),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $review = Review::query()->with('images')->sole();
        $this->assertSame(Review::CHANNEL_MANUAL, $review->channel);
        $this->assertSame('Maria S.', $review->reviewer_display_name);
        $this->assertSame(4, $review->rating);
        $this->assertSame('2026-10-10', $review->created_at->toDateString());
        $this->assertSame(['image', 'video'], $review->images->pluck('media_type')->all());
        $this->assertCount(2, Storage::disk('public')->files('avaliacoes'));
    }

    public function test_recusa_arquivo_que_nao_e_foto_nem_video_e_produto_inativo(): void
    {
        $inativo = Product::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin())->post('/admin/avaliacoes/controle', [
            'product_id' => $inativo->id,
            'reviewer_name' => 'João',
            'rating' => 5,
            'midias' => [UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf')],
        ])->assertSessionHasErrors(['product_id', 'midias.0']);

        $this->assertSame(0, Review::count());
    }

    public function test_conta_anuncios_distintos_da_familia_e_compara_com_o_site(): void
    {
        $pai = Product::factory()->create(['is_active' => true, 'name' => 'Power Bank']);
        $rosa = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id]);
        $azul = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id]);
        // Mesmo item da Shopee nas duas variações: conta uma vez só.
        $this->vincular($rosa, MarketplaceAccount::CHANNEL_SHOPEE, '111');
        $this->vincular($azul, MarketplaceAccount::CHANNEL_SHOPEE, '111');
        $this->vincular($pai, MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'MLB1');
        $this->vincular($azul, MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'MLB2');

        $shopee = $this->mock(ShopeeClient::class);
        $shopee->shouldReceive('get')->once()->with('/api/v2/product/get_item_extra_info', ['item_id_list' => '111'])
            ->andReturn(['response' => ['item_list' => [['item_id' => 111, 'comment_count' => 12, 'rating_star' => 4.8]]]]);
        $ml = $this->mock(MercadoLivreClient::class);
        $ml->shouldReceive('get')->with('/reviews/item/MLB1', ['limit' => 1])->andReturn(['paging' => ['total' => 3], 'rating_average' => 5]);
        $ml->shouldReceive('get')->with('/reviews/item/MLB2', ['limit' => 1])->andThrow(new \RuntimeException('403'));

        $resumo = app(ContagemAvaliacoesCanais::class)->atualizar();
        $this->assertSame(['shopee' => 1, 'mercado_livre' => 1, 'erros' => 1], $resumo);
        $this->assertSame('403', ContagemAvaliacoesCanal::where('external_id', 'MLB2')->value('erro'));

        Review::create(['product_id' => $rosa->id, 'rating' => 5, 'reviewer_name' => 'A', 'channel' => 'shopee', 'external_id' => 'c1']);
        Review::create(['product_id' => $pai->id, 'rating' => 5, 'reviewer_name' => 'B', 'channel' => Review::CHANNEL_MANUAL]);

        $this->actingAs($this->admin())->get('/admin/avaliacoes/controle')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Reviews/Controle', false)
                ->has('produtos', 1)
                ->where('produtos.0.nome', 'Power Bank')
                ->where('produtos.0.shopee.total', 12)
                ->where('produtos.0.shopee.nota', 4.8)
                ->where('produtos.0.mercado_livre.total', 3)
                ->where('produtos.0.mercado_livre.sem_leitura', 1)
                ->where('produtos.0.site.total', 2)
                ->where('produtos.0.site.shopee', 1)
                ->where('produtos.0.site.manual', 1));
    }

    public function test_botao_atualizar_manda_para_a_fila(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->post('/admin/avaliacoes/controle/atualizar-contagem')->assertRedirect();

        Queue::assertPushed(ContarAvaliacoesCanaisJob::class);
    }
}
