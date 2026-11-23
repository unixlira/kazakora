<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\Editorial\Models\EditorialPost;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Blog do site FerraKora (23/11/2026): mesmas matérias do admin editorial,
 * separadas do blog da KazaKora pela coluna "site".
 */
class FerraKoraBlogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function materia(array $dados = []): EditorialPost
    {
        return EditorialPost::create(array_merge([
            'site' => 'ferrakora', 'slug' => 'como-escolher-compressor', 'title' => 'Como escolher compressor',
            'excerpt' => 'Resumo', 'body' => "Primeiro parágrafo.\n\nSegundo parágrafo.", 'type' => 'news', 'category' => 'automotivo',
            'status' => 'published', 'published_at' => now()->subMinute(), 'rights_confirmed' => true, 'editorial_checked' => true,
        ], $dados));
    }

    private function produtoNoSite(string $sku, string $categoria, bool $ativo = true): Product
    {
        $produto = Product::factory()->create([
            'sku' => $sku, 'name' => 'Produto '.$sku, 'price' => 99.90, 'stock' => 5, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        FerraKoraProduto::create(['product_id' => $produto->id, 'categoria' => $categoria, 'ordem' => 1, 'ativo' => $ativo]);

        return $produto;
    }

    public function test_sites_nao_se_misturam(): void
    {
        $this->materia(['slug' => 'so-ferrakora', 'category' => 'casa']);
        EditorialPost::create(['slug' => 'so-kazakora', 'title' => 'Receita', 'body' => 'Texto', 'type' => 'news', 'category' => 'casa', 'status' => 'published', 'published_at' => now()->subMinute(), 'rights_confirmed' => true, 'editorial_checked' => true]);

        $this->assertSame('kazakora', EditorialPost::where('slug', 'so-kazakora')->value('site'));

        // Blog da KazaKora: só a dela.
        $this->get('/blog')->assertInertia(fn ($p) => $p->has('posts', 1)->where('posts.0.slug', 'so-kazakora')->has('categories', 1)->where('categories.0.count', 1));
        $this->get('/blog/so-ferrakora')->assertNotFound();
        $this->get('/blog/so-kazakora')->assertOk()->assertInertia(fn ($p) => $p->has('related', 0));

        // API do FerraKora: só a dele.
        $this->getJson('/api/ferrakora/blog')->assertOk()->assertJsonCount(1, 'posts')->assertJsonPath('posts.0.slug', 'so-ferrakora');
        $this->getJson('/api/ferrakora/blog/so-kazakora')->assertNotFound();
    }

    public function test_lista_paginada_com_categorias_e_sem_corpo(): void
    {
        $this->produtoNoSite('FK-1', 'Ferramentas Elétricas');
        for ($i = 0; $i < 10; $i++) {
            $this->materia(['slug' => 'materia-'.$i, 'category' => 'ferramentas-eletricas', 'published_at' => now()->subMinutes(20 - $i)]);
        }
        $this->materia(['slug' => 'pneu', 'category' => 'automotivo']);
        $this->materia(['slug' => 'rascunho', 'status' => 'draft']);
        $this->materia(['slug' => 'futura', 'published_at' => now()->addDay()]);

        $resposta = $this->getJson('/api/ferrakora/blog')->assertOk()
            ->assertJsonCount(9, 'posts')
            ->assertJsonPath('total', 11)
            ->assertJsonPath('pagination.current', 1)
            ->assertJsonPath('pagination.last', 2)
            ->assertJsonPath('posts.0.slug', 'pneu')
            ->assertJsonPath('categories.0.slug', 'ferramentas-eletricas')
            ->assertJsonPath('categories.0.name', 'Ferramentas Elétricas')
            ->assertJsonPath('categories.0.count', 10);
        $this->assertArrayNotHasKey('body', $resposta->json('posts.0'));

        $this->getJson('/api/ferrakora/blog?page=2')->assertOk()->assertJsonCount(2, 'posts')->assertJsonPath('pagination.current', 2);
        $this->getJson('/api/ferrakora/blog?page=99')->assertOk()->assertJsonPath('pagination.current', 2);
        $this->getJson('/api/ferrakora/blog?categoria=automotivo')->assertOk()->assertJsonCount(1, 'posts')->assertJsonPath('posts.0.category_name', 'Automotivo');
        $this->getJson('/api/ferrakora/blog?categoria=nada')->assertOk()->assertJsonCount(0, 'posts');
    }

    public function test_materia_com_produtos_da_vitrine_e_relacionadas(): void
    {
        $escolhido = $this->produtoNoSite('FK-COMP', 'Automotivo');
        $foraDoSite = Product::factory()->create(['is_active' => true, 'stock' => 3, 'price' => 50]);
        $inativo = $this->produtoNoSite('FK-OFF', 'Automotivo', false);
        $post = $this->materia();
        $post->products()->attach([$escolhido->id, $foraDoSite->id, $inativo->id]);
        $this->materia(['slug' => 'outra-1']);
        $this->materia(['slug' => 'outra-2']);
        $this->materia(['slug' => 'outra-3']);
        $this->materia(['slug' => 'outra-4']);

        $this->getJson('/api/ferrakora/blog/como-escolher-compressor')->assertOk()
            ->assertJsonPath('post.title', 'Como escolher compressor')
            ->assertJsonPath('post.body', "Primeiro parágrafo.\n\nSegundo parágrafo.")
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', 'kz-'.$escolhido->id)
            ->assertJsonPath('products.0.categoria', 'Automotivo')
            ->assertJsonCount(3, 'related');
    }

    public function test_sem_produto_escolhido_usa_a_categoria_do_site(): void
    {
        $daCategoria = $this->produtoNoSite('FK-A', 'Automotivo');
        $this->produtoNoSite('FK-B', 'Jardim e Limpeza');
        $this->materia();

        $this->getJson('/api/ferrakora/blog/como-escolher-compressor')->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', 'kz-'.$daCategoria->id);
    }

    public function test_rascunho_e_slug_invalido_dao_404(): void
    {
        $this->materia(['status' => 'draft']);

        $this->getJson('/api/ferrakora/blog/como-escolher-compressor')->assertNotFound();
        $this->getJson('/api/ferrakora/blog/Nao_Valido')->assertNotFound();
    }

    public function test_admin_grava_o_site_e_recusa_site_desconhecido(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $form = ['slug' => 'nova', 'title' => 'Nova', 'body' => 'Texto puro', 'type' => 'news', 'category' => 'automotivo', 'status' => 'draft', 'rights_confirmed' => false, 'editorial_checked' => false, 'product_ids' => []];

        $this->actingAs($admin)->post('/admin/editorial/posts', [...$form, 'site' => 'ferrakora'])->assertRedirect();
        $this->assertSame('ferrakora', EditorialPost::where('slug', 'nova')->value('site'));

        $this->post('/admin/editorial/posts', [...$form, 'slug' => 'outra', 'site' => 'beautykora'])->assertSessionHasErrors('site');

        $this->post('/admin/editorial/posts', [...$form, 'slug' => 'sem-site'])->assertRedirect();
        $this->assertSame('kazakora', EditorialPost::where('slug', 'sem-site')->value('site'));
    }
}
