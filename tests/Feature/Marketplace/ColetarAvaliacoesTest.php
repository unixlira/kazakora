<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\AnonimizaAvaliacao;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * reviews:coletar (pedido do Lira 2026-10-22): Shopee + ML, nome sorteado
 * único na página do produto e texto sem citar marketplace ou loja.
 */
class ColetarAvaliacoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tira_frase_que_cita_loja_e_mantem_o_resto(): void
    {
        $this->assertSame(
            'Produto excelente. Recomendo!',
            AnonimizaAvaliacao::limparTexto('Produto excelente. Comprei na Shopee porque no Mercado Livre estava caro. Recomendo!'),
        );
        $this->assertNull(AnonimizaAvaliacao::limparTexto('Melhor que o da Amazon'));
        $this->assertSame('Garrafa de 500 ml, veio certinha', AnonimizaAvaliacao::limparTexto('Garrafa de 500 ml, veio certinha'));
        $this->assertNull(AnonimizaAvaliacao::limparTexto('   '));
    }

    public function test_coleta_sorteia_nomes_unicos_e_limpa_texto(): void
    {
        $produto = Product::factory()->create();
        ProductChannelListing::query()->create(['product_id' => $produto->id, 'channel' => 'shopee', 'external_id' => '111', 'is_enabled' => true]);
        ProductChannelListing::query()->create(['product_id' => $produto->id, 'channel' => 'mercado_livre', 'external_id' => 'MLB1', 'is_enabled' => true]);

        // Avaliação antiga, de antes da limpeza: apelido e texto com a Shopee.
        $antiga = Review::query()->forceCreate([
            'product_id' => $produto->id, 'rating' => 5, 'comment' => 'Chegou rápido. Melhor loja da Shopee.',
            'reviewer_name' => 'deborabrito932', 'channel' => 'shopee', 'external_id' => 'old-1',
        ]);

        $avaliacao = fn (string $id, string $nome) => [
            'external_id' => $id, 'rating' => 5, 'comment' => 'Muito bom', 'reviewer_name' => $nome,
            'images' => [], 'model_ids' => [], 'created_at' => null,
        ];

        $shopee = Mockery::mock(MarketplaceChannelDriver::class);
        $shopee->shouldReceive('fetchReviews')->with('111')->andReturn([$avaliacao('s-1', 'm*****a'), $avaliacao('s-2', 'm*****a')]);
        $ml = Mockery::mock(MarketplaceChannelDriver::class);
        $ml->shouldReceive('fetchReviews')->with('MLB1')->andReturn([$avaliacao('m-1', '')]);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with('shopee')->andReturn($shopee);
        $manager->shouldReceive('driver')->with('mercado_livre')->andReturn($ml);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $this->artisan('reviews:coletar')->assertSuccessful();

        $nomes = Review::query()->where('product_id', $produto->id)->pluck('reviewer_name');
        $this->assertCount(4, $nomes);
        $this->assertCount(4, $nomes->unique());
        $this->assertFalse($nomes->contains('m*****a'));
        $this->assertFalse($nomes->contains('deborabrito932'));

        $antiga->refresh();
        $this->assertSame('deborabrito932', $antiga->nome_original);
        $this->assertSame('Chegou rápido.', $antiga->comment);
        $this->assertSame('Chegou rápido. Melhor loja da Shopee.', $antiga->comentario_original);

        // Segunda passada não troca o nome já sorteado.
        $nome = $antiga->reviewer_name;
        $this->artisan('reviews:coletar')->assertSuccessful();
        $this->assertSame($nome, $antiga->refresh()->reviewer_name);
        $this->assertArrayNotHasKey('nome_original', $antiga->toArray());
    }

    public function test_coleta_manual_so_do_produto_escolhido_e_sem_agendamento(): void
    {
        $escolhido = Product::factory()->create();
        $outro = Product::factory()->create();
        ProductChannelListing::query()->create(['product_id' => $escolhido->id, 'channel' => 'shopee', 'external_id' => '111', 'is_enabled' => true]);
        ProductChannelListing::query()->create(['product_id' => $outro->id, 'channel' => 'shopee', 'external_id' => '222', 'is_enabled' => true]);

        $shopee = Mockery::mock(MarketplaceChannelDriver::class);
        $shopee->shouldReceive('fetchReviews')->with('111')->once()->andReturn([[
            'external_id' => 's-1', 'rating' => 5, 'comment' => 'Muito bom', 'reviewer_name' => 'ana_1',
            'images' => [], 'model_ids' => [], 'created_at' => null,
        ]]);
        $shopee->shouldNotReceive('fetchReviews')->with('222');

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->andReturn($shopee);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $this->artisan('reviews:coletar', ['--produto' => [$escolhido->id]])->assertSuccessful();

        $this->assertSame(1, Review::query()->where('product_id', $escolhido->id)->count());
        $this->assertSame(0, Review::query()->where('product_id', $outro->id)->count());

        $agendados = collect(app(Schedule::class)->events())
            ->map(fn ($evento) => $evento->command);
        $this->assertFalse($agendados->contains(fn ($comando) => str_contains((string) $comando, 'reviews:coletar')));
    }
}
