<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Avaliação feita em outra loja (pedido do Lira 2026-10-18): aparece com o
 * selo, em bloco próprio, com nome como recebido da origem, e NÃO entra na nota, na
 * contagem, na estrela do card nem no JSON-LD do produto.
 */
class AvaliacoesOutraLojaTest extends TestCase
{
    use RefreshDatabase;

    private function daLoja(Product $produto, int $nota): Review
    {
        return Review::create(['user_id' => User::factory()->create(['name' => 'Maria Cliente'])->id, 'product_id' => $produto->id, 'rating' => $nota, 'comment' => "Da loja {$nota}"]);
    }

    private function deOutraLoja(Product $produto, int $nota, string $autor = 'j_lucas_oliveira'): Review
    {
        return Review::create([
            'product_id' => $produto->id,
            'rating' => $nota,
            'comment' => "De outra loja {$nota}",
            'reviewer_name' => $autor,
            'channel' => Review::CHANNEL_OUTRA_LOJA,
            'external_id' => 'shopee:'.uniqid(),
            'outra_loja' => true,
            'origem_loja' => 'KICOOL COMERCIAL (Shopee) https://shopee.com.br/product/1/2',
        ]);
    }

    public function test_pagina_separa_as_de_outra_loja_com_selo_e_nome_da_origem(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $loja = $this->daLoja($produto, 5);
        $outra = $this->deOutraLoja($produto, 1);

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('reviews', 1)
            ->where('reviews.0.id', $loja->id)
            ->where('reviews.0.outra_loja', false)
            ->has('reviewsOutraLoja', 1)
            ->where('reviewsOutraLoja.0.id', $outra->id)
            ->where('reviewsOutraLoja.0.outra_loja', true)
            ->where('reviewsOutraLoja.0.reviewer_display_name', 'j_lucas_oliveira')
            ->missing('reviewsOutraLoja.0.origem_loja')
            ->missing('reviewsOutraLoja.0.channel')
            ->missing('reviewsOutraLoja.0.external_id')
            ->where('outraLojaResumo.selo', 'Avaliação feita em outra loja')
            ->where('outraLojaResumo.total', 1)
            ->where('outraLojaResumo.media', 1));

        // O selo existe nas duas versões da página do produto.
        foreach (['ProductDetail.vue', 'ProductDetailV2.vue'] as $arquivo) {
            $vue = file_get_contents(resource_path('js/Modules/Catalog/'.$arquivo));
            $this->assertStringContainsString('selo-outra-loja', $vue);
            $this->assertStringContainsString('Avaliações deste produto em outras lojas', $vue);
        }
    }

    public function test_media_e_contagem_contam_so_as_da_kazakora(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->daLoja($produto, 4);
        $this->deOutraLoja($produto, 1);
        $this->deOutraLoja($produto, 1);

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.reviews_count', 1)
            ->where('product.reviews_avg_rating', 4));

        $card = Product::query()->forCard()->find($produto->id);
        $this->assertEquals(4, (float) $card->reviews_avg_rating);
    }

    public function test_sem_avaliacao_da_loja_o_card_fica_sem_estrela(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->deOutraLoja($produto, 5);

        $this->assertNull(Product::query()->forCard()->find($produto->id)->reviews_avg_rating);

        $this->get('/produtos/'.$produto->slug)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('product.reviews_count', 0)
            ->where('product.reviews_avg_rating', null)
            ->has('reviews', 0)
            ->has('reviewsOutraLoja', 1));
    }

    public function test_json_ld_nao_usa_as_de_outra_loja(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->daLoja($produto, 4);
        $this->deOutraLoja($produto, 1, 'concorrente_x');

        $html = $this->get('/produtos/'.$produto->slug)->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $produtoLd = collect($m[1])->map(fn ($j) => json_decode($j, true))->firstWhere('@type', 'Product');

        $this->assertNotNull($produtoLd);
        $this->assertSame(1, $produtoLd['aggregateRating']['reviewCount']);
        $this->assertSame('4.0', $produtoLd['aggregateRating']['ratingValue']);
        $this->assertCount(1, $produtoLd['review']);
        $this->assertSame('Maria Cliente', $produtoLd['review'][0]['author']['name']);
        $this->assertStringNotContainsString('De outra loja', json_encode($produtoLd, JSON_UNESCAPED_UNICODE));
    }

    public function test_json_ld_sem_nota_quando_so_tem_de_outra_loja(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->deOutraLoja($produto, 5);

        $html = $this->get('/produtos/'.$produto->slug)->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $produtoLd = collect($m[1])->map(fn ($j) => json_decode($j, true))->firstWhere('@type', 'Product');

        $this->assertArrayNotHasKey('aggregateRating', $produtoLd);
        $this->assertArrayNotHasKey('review', $produtoLd);
    }

    public function test_api_do_app_separa_as_de_outra_loja(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $this->daLoja($produto, 5);
        $this->deOutraLoja($produto, 2);

        $this->getJson('/api/app/produtos/'.$produto->slug)->assertOk()
            ->assertJsonPath('produto.avaliacoes.total', 1)
            ->assertJsonPath('produto.avaliacoes.media', 5)
            ->assertJsonCount(1, 'produto.avaliacoes.itens')
            ->assertJsonPath('produto.avaliacoes.itens.0.outra_loja', false)
            ->assertJsonPath('produto.avaliacoes.outras_lojas.total', 1)
            ->assertJsonPath('produto.avaliacoes.outras_lojas.media', 2)
            ->assertJsonPath('produto.avaliacoes.outras_lojas.itens.0.selo', 'Avaliação feita em outra loja')
            ->assertJsonPath('produto.avaliacoes.outras_lojas.itens.0.nome', 'j_lucas_oliveira');
    }

    public function test_anonimizar_nome(): void
    {
        $this->assertSame('j***a', Review::anonimizarNome('j_lucas_oliveira'));
        $this->assertSame('J***a', Review::anonimizarNome('J***a'));
        $this->assertSame('g***o', Review::anonimizarNome('g*****o'));
        $this->assertSame('Comprador de outra loja', Review::anonimizarNome(null));
        $this->assertSame('Comprador de outra loja', Review::anonimizarNome('AliExpress Shopper'));
        $this->assertSame('Comprador de outra loja', Review::anonimizarNome('ab'));
    }

    private function arquivo(array $avaliacoes, $produto): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'aval').'.json';
        file_put_contents($caminho, json_encode(['produto_kazakora' => $produto, 'avaliacoes' => $avaliacoes]));

        return $caminho;
    }

    private function item(string $id, int $nota, array $extra = []): array
    {
        return array_merge([
            'origem' => 'kicool', 'canal' => 'shopee', 'loja' => 'KICOOL COMERCIAL (Shopee)',
            'anuncio' => 'https://shopee.com.br/product/1224304136/18497998841',
            'id_avaliacao' => $id, 'autor' => 'comprador_'.$id, 'anonimo' => false,
            'nota' => $nota, 'texto' => "Texto {$id}", 'variacao' => null,
            'data' => '2026-09-10 14:30', 'fotos' => [], 'videos' => [],
        ], $extra);
    }

    public function test_import_idempotente_sem_filtrar_nota(): void
    {
        Storage::fake('public');
        Http::fake([
            'img.exemplo.com/*' => Http::response("\xFF\xD8\xFF\xE0fakejpeg", 200, ['Content-Type' => 'image/jpeg']),
            'video.exemplo.com/*' => Http::response("\x00\x00\x00\x18ftypmp42", 200, ['Content-Type' => 'video/mp4']),
        ]);
        $produto = Product::factory()->create(['is_active' => true]);

        $arquivo = $this->arquivo([
            $this->item('1', 5, ['fotos' => ['https://img.exemplo.com/a'], 'videos' => ['https://video.exemplo.com/v.mp4', 'https://video.exemplo.com/stream/master.m3u8']]),
            $this->item('2', 1),
            $this->item('2', 1), // repetida no arquivo
            $this->item('3', 3, ['origem' => 'kazakora']), // já vem pelo reviews:sync
            $this->item('4', 2, ['canal' => 'mercado_livre', 'autor' => null, 'anonimo' => true, 'data' => '2026-08-08']),
        ], $produto->id);

        $this->artisan('reviews:importar-outra-loja', ['arquivo' => $arquivo, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Review::query()->count());
        Http::assertNothingSent();

        $this->artisan('reviews:importar-outra-loja', ['arquivo' => $arquivo])->assertSuccessful();
        $this->artisan('reviews:importar-outra-loja', ['arquivo' => $arquivo])->assertSuccessful();

        $reviews = Review::query()->with('images')->orderBy('id')->get();
        $this->assertCount(3, $reviews);
        $this->assertEqualsCanonicalizing([5, 1, 2], $reviews->pluck('rating')->all());
        $this->assertTrue($reviews->every(fn ($r) => $r->outra_loja && $r->channel === 'outra_loja' && $r->product_id === $produto->id));
        $this->assertEqualsCanonicalizing(['shopee:1', 'shopee:2', 'mercado_livre:4'], $reviews->pluck('external_id')->all());

        $primeira = $reviews->firstWhere('external_id', 'shopee:1');
        $this->assertSame('comprador_1', $primeira->reviewer_name);
        $this->assertSame('KICOOL COMERCIAL (Shopee) https://shopee.com.br/product/1224304136/18497998841', $primeira->origem_loja);
        $this->assertSame('2026-09-10 14:30', $primeira->created_at->format('Y-m-d H:i'));
        // Foto e vídeo MP4 baixados uma vez só; o m3u8 (streaming) fica de fora.
        $this->assertSame(['image', 'video'], $primeira->images->pluck('media_type')->all());
        $this->assertStringContainsString('/storage/avaliacoes/outra-loja/', $primeira->images[0]->image_url);
        Storage::disk('public')->assertExists('avaliacoes/outra-loja/'.sha1('https://img.exemplo.com/a').'.jpg');

        $this->assertSame('Comprador de outra loja', $reviews->firstWhere('external_id', 'mercado_livre:4')->reviewer_display_name);
    }

    public function test_import_com_produto_por_item_e_override(): void
    {
        Http::fake();
        $p177 = Product::factory()->create(['is_active' => true]);
        $p178 = Product::factory()->create(['is_active' => true, 'parent_product_id' => $p177->id]);
        $p999 = Product::factory()->create(['is_active' => true]);

        $arquivo = $this->arquivo([
            $this->item('10', 5, ['produto_kazakora' => $p177->id]),
            $this->item('11', 4, ['produto_kazakora' => $p178->id]),
        ], [$p177->id, $p178->id]);

        // Vários --produto: filtra pelos do item.
        $this->artisan('reviews:importar-outra-loja', ['arquivo' => $arquivo, '--produto' => [$p178->id]])->assertSuccessful();
        $this->assertSame([$p178->id, $p178->id], Review::query()->orderBy('id')->pluck('product_id')->all());

        Review::query()->delete();
        $this->artisan('reviews:importar-outra-loja', ['arquivo' => $arquivo])->assertSuccessful();
        $this->assertEqualsCanonicalizing([$p177->id, $p178->id], Review::query()->pluck('product_id')->all());

        Review::query()->delete();
        $this->artisan('reviews:importar-outra-loja', ['arquivo' => $arquivo, '--produto' => [$p177->id, $p999->id]])->assertSuccessful();
        $this->assertSame([$p177->id], Review::query()->pluck('product_id')->all());
    }

    public function test_limite_pega_as_mais_recentes_e_min_texto(): void
    {
        Http::fake();
        $produto = Product::factory()->create(['is_active' => true]);
        $arquivo = $this->arquivo([
            $this->item('a', 5, ['data' => '2026-01-01 10:00']),
            $this->item('b', 1, ['data' => '2026-10-01 10:00']),
            $this->item('c', 2, ['data' => '2026-09-01 10:00', 'texto' => 'ok']),
        ], $produto->id);

        $this->artisan('reviews:importar-outra-loja', ['arquivo' => $arquivo, '--limite' => 2])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['shopee:b', 'shopee:c'], Review::query()->pluck('external_id')->all());

        Review::query()->delete();
        $this->artisan('reviews:importar-outra-loja', ['arquivo' => $arquivo, '--min-texto' => 5])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['shopee:a', 'shopee:b'], Review::query()->pluck('external_id')->all());
    }
}
