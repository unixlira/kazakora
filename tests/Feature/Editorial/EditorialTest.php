<?php

namespace Tests\Feature\Editorial;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Editorial\Models\EditorialPost;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Storage::fake('public');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
    }

    private function editorial(array $data = []): EditorialPost
    {
        return EditorialPost::create(array_merge(['slug' => 'teste', 'title' => 'Título real', 'body' => 'Texto editorial seguro', 'type' => 'news', 'category' => 'casa', 'status' => 'published', 'published_at' => now()->subMinute(), 'rights_confirmed' => true, 'editorial_checked' => true], $data));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function form(array $data = []): array
    {
        return array_merge(['slug' => 'novo', 'title' => 'Nova pauta', 'body' => 'Texto sem HTML', 'type' => 'news', 'category' => 'casa', 'status' => 'draft', 'rights_confirmed' => false, 'editorial_checked' => false, 'product_ids' => []], $data);
    }

    public function test_feed_filters_paginates_and_supplies_interactions(): void
    {
        for ($i = 0; $i < 18; $i++) {
            $this->editorial(['slug' => 'receita-'.$i, 'type' => 'recipe']);
        }
        $this->editorial(['slug' => 'outra', 'category' => 'jardim']);
        $this->get('/blog?categoria=casa&tipo=recipe&utm_source=x')->assertInertia(fn ($p) => $p
            ->has('posts', 9)->where('posts.0.interaction.canInteract', false)
            ->where('hasMore', true)->where('filters.categoria', 'casa')
            ->where('nextPageUrl', '/blog?categoria=casa&tipo=recipe&page=2')
            ->where('pagination.current', 1)->where('pagination.last', 3)
            ->where('pagination.links.0.url', '/blog?categoria=casa&tipo=recipe')->where('pagination.links.0.current', true)
            ->where('pagination.links.1.url', '/blog?categoria=casa&tipo=recipe&page=2'));
        // Da 2ª página em diante são 6 (pedido do Lira 27/11/2026): 18 = 9 + 6 + 3.
        $this->get('/blog?categoria=casa&tipo=recipe&page=2')->assertInertia(fn ($p) => $p->has('posts', 6)->where('hasMore', true)->where('pagination.current', 2)
            ->where('posts.0.slug', 'receita-8'));
        $this->get('/blog?categoria=casa&tipo=recipe&page=3')->assertInertia(fn ($p) => $p->has('posts', 3)->where('hasMore', false)->where('pagination.current', 3));
        $this->get('/blog?categoria=casa&tipo=recipe&page=99')->assertInertia(fn ($p) => $p->has('posts', 3)->where('pagination.current', 3));
        $this->get('/blog?categoria=inexistente')->assertInertia(fn ($p) => $p->has('posts', 0));
        $this->get('/blog?categoria=&tipo=')->assertOk();
    }

    public function test_admin_catalog_search_and_selected_outside_first_page(): void
    {
        $products = Product::factory()->count(52)->create(['is_active' => true, 'so_ferrakora' => false, 'stock' => 3, 'price' => 99]);
        $last = $products->last();
        $last->update(['name' => 'Produto procurado exclusivo']);
        $this->actingAs(User::factory()->create())->getJson('/admin/editorial/products?q=exclusivo')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/admin/editorial/products?q=exclusivo')->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $last->id)->assertJsonMissingPath('products.0.cost_price');
        $this->getJson('/admin/editorial/products')->assertOk()->assertJsonCount(50, 'products')->assertJsonPath('hasMore', true);
        $post = $this->editorial();
        $post->products()->attach($last->id);
        $this->get('/admin/editorial/posts/'.$post->id.'/edit')->assertInertia(fn ($p) => $p->where('selectedProducts.0.id', $last->id));
    }

    public function test_generic_links_insults_and_word_boundaries(): void
    {
        foreach (['acesse exemplo.xyz', 'Oferta exemplo.dev', 'xn--bcher-kva.xyz', 'exemplo [ponto] dev', "exem\u{200B}plo . xyz", 'Você é um idiota', '1d10t4', 'ｉｄｉｏｔａ'] as $body) {
            $this->assertNull(\App\Modules\Editorial\Support\EditorialSafety::comment($body), $body);
        }
        foreach (['A disputa foi bonita', 'Computador bacana', 'Essa reportagem importa'] as $body) {
            $this->assertSame($body, \App\Modules\Editorial\Support\EditorialSafety::comment($body));
        }
    }

    public function test_video_requires_media_only_when_publishing(): void
    {
        $this->actingAs($this->admin());
        $post = $this->editorial(['type' => 'video', 'status' => 'draft']);
        $this->postJson('/admin/editorial/posts/'.$post->id.'/publish')->assertUnprocessable()->assertJsonValidationErrors('video_url');
        foreach (['news', 'recipe'] as $type) {
            $post->update(['type' => $type]);
            $this->post('/admin/editorial/posts/'.$post->id.'/publish')->assertRedirect();
        }
    }

    public function test_category_fallback_excludes_unrelated_products(): void
    {
        $this->editorial();
        $category = Category::factory()->create(['slug' => 'casa']);
        $included = Product::factory()->create(['category_id' => $category->id, 'is_active' => true, 'so_ferrakora' => false, 'stock' => 2, 'price' => 78]);
        Product::factory()->create(['is_active' => true, 'so_ferrakora' => false, 'stock' => 4]);
        $this->get('/blog/teste')->assertInertia(fn ($p) => $p->has('products', 1)->where('products.0.id', $included->id));
    }

    public function test_csrf_required_even_for_authenticated_reader(): void
    {
        $this->editorial();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs(User::factory()->create())->putJson('/blog/teste/like')->assertStatus(419);
    }

    public function test_admin_can_delete_post_and_cascades_interactions(): void
    {
        $post = $this->editorial();
        $reader = User::factory()->create();
        $this->actingAs($reader)->putJson('/blog/teste/like')->assertOk();
        $this->deleteJson('/admin/editorial/posts/'.$post->id)->assertForbidden();
        $this->actingAs($this->admin())->delete('/admin/editorial/posts/'.$post->id)->assertRedirect('/admin/editorial/posts');
        $this->assertDatabaseMissing('editorial_posts', ['id' => $post->id]);
        $this->assertDatabaseCount('editorial_likes', 0);
    }

    public function test_share_preview_uses_blog_image(): void
    {
        $this->get('/blog')->assertOk()->assertSee('/images/marca/og-blog-kazakora.jpg', false);
        $this->editorial();
        $this->get('/blog/teste')->assertOk()->assertSee('/images/marca/og-blog-kazakora.jpg', false);
        $this->get('/')->assertDontSee('og-blog-kazakora', false);
    }

    public function test_feed_route_exists(): void
    {
        $this->get('/blog')->assertOk()->assertInertia(fn ($p) => $p->component('Blog/Index', false)->has('posts', 0));
    }

    public function test_drafts_and_future_are_hidden(): void
    {
        $p = $this->editorial(['status' => 'draft']);
        $this->get('/blog/teste')->assertNotFound();
        $p->update(['status' => 'published', 'published_at' => now()->addDay()]);
        $this->get('/blog/teste')->assertNotFound();
        $this->get('/blog')->assertInertia(fn ($p) => $p->has('posts', 0));
    }

    public function test_public_post_and_safe_product_projection(): void
    {
        $p = $this->editorial();
        $product = Product::factory()->create(['is_active' => true, 'so_ferrakora' => false, 'stock' => 3, 'price' => 120, 'cost_price' => 61]);
        $empty = Product::factory()->create(['is_active' => true, 'stock' => 0]);
        $p->products()->attach([$product->id, $empty->id]);
        $this->get('/blog/teste')->assertOk()->assertInertia(fn ($page) => $page->component('Blog/Show', false)->where('post.body', 'Texto editorial seguro')->has('products', 1)->where('products.0.id', $product->id)->where('products.0.price', 120)->missing('products.0.cost_price')->where('interaction.canInteract', false));
    }

    public function test_only_admin_can_manage(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']))->get('/admin/editorial/posts')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/editorial/posts')->assertOk();
    }

    public function test_crud_publish_requires_rights_and_editorial_check(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/editorial/posts', $this->form())->assertRedirect();
        $p = EditorialPost::firstOrFail();
        $this->postJson('/admin/editorial/posts/'.$p->id.'/publish')->assertUnprocessable();
        $this->put('/admin/editorial/posts/'.$p->id, $this->form(['rights_confirmed' => true, 'editorial_checked' => true]))->assertRedirect();
        $this->post('/admin/editorial/posts/'.$p->id.'/publish')->assertRedirect();
        $this->assertSame('published', $p->fresh()->status);
        $this->assertSame($admin->id, $p->fresh()->reviewer_id);
        $this->get('/blog/novo')->assertOk();
        $this->post('/admin/editorial/posts/'.$p->id.'/unpublish')->assertRedirect();
        $this->get('/blog/novo')->assertNotFound();
    }

    public function test_editing_published_content_unpublishes(): void
    {
        $p = $this->editorial();
        $this->actingAs($this->admin())->put('/admin/editorial/posts/'.$p->id, $this->form())->assertRedirect();
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_media_and_source_reject_unsafe_urls(): void
    {
        $this->actingAs($this->admin());
        foreach (['https://evil.example/a.mp4', '/storage/../a.mp4', '//evil.test/a.mp4', '/storage/%252e%252e/a.mp4', 'javascript:alert(1)'] as $url) {
            $this->postJson('/admin/editorial/posts', $this->form(['video_url' => $url]))->assertUnprocessable();
        } $this->postJson('/admin/editorial/posts', $this->form(['source_url' => 'javascript:alert(1)']))->assertUnprocessable();
        $this->post('/admin/editorial/posts', $this->form(['video_url' => '/storage/editorial/safe.mp4', 'poster_url' => '/storage/editorial/safe.webp', 'source_url' => 'https://example.org/source']))->assertRedirect();
    }

    public function test_likes_are_authenticated_idempotent_and_real(): void
    {
        $p = $this->editorial();
        $this->putJson('/blog/teste/like')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->putJson('/blog/teste/like')->assertOk()->assertJson(['liked' => true, 'likesCount' => 1]);
        $this->putJson('/blog/teste/like')->assertJson(['likesCount' => 1]);
        $this->deleteJson('/blog/teste/like')->assertJson(['liked' => false, 'likesCount' => 0]);
        $this->deleteJson('/blog/teste/like')->assertJson(['likesCount' => 0]);
        $this->assertDatabaseCount('editorial_likes', 0);
    }

    public function test_comment_pending_then_human_approved_and_audited(): void
    {
        $p = $this->editorial();
        $this->actingAs(User::factory()->create(['name' => 'Leitora']))->postJson('/blog/teste/comments', ['body' => 'Gostei desta ideia'])->assertCreated()->assertJson(['status' => 'pending']);
        $this->get('/blog/teste')->assertInertia(fn ($p) => $p->has('comments', 0));
        $id = DB::table('editorial_comments')->value('id');
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/editorial/comments/'.$id.'/approve')->assertRedirect();
        $this->get('/blog/teste')->assertInertia(fn ($p) => $p->has('comments', 1)->where('comments.0.body', 'Gostei desta ideia'));
        $this->assertDatabaseHas('editorial_comments', ['id' => $id, 'moderator_id' => $admin->id, 'status' => 'approved']);
        $this->post('/admin/editorial/comments/'.$id.'/reject')->assertRedirect();
        $this->get('/blog/teste')->assertInertia(fn ($p) => $p->has('comments', 0));
    }

    public function test_comments_reject_html_obfuscated_links_and_profanity(): void
    {
        $this->editorial();
        $this->actingAs(User::factory()->create());
        foreach (['<img src=x onerror=alert(1)>', 'https://evil.test', 'h t t p s : / / evil.test', 'evil [dot] com', 'v0c3 é p0rr4', 'pоrra', "p\u{200B}orra", 'ｐｏｒｒａ'] as $body) {
            $this->actingAs(User::factory()->create());
            $this->postJson('/blog/teste/comments', ['body' => $body])->assertUnprocessable();
        } $this->assertDatabaseCount('editorial_comments', 0);
    }

    public function test_comments_throttle(): void
    {
        $this->editorial();
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/blog/teste/comments', ['body' => 'Comentário respeitoso'])->assertCreated();
        } $this->postJson('/blog/teste/comments', ['body' => 'Mais um'])->assertStatus(429);
    }

    public function test_preview_is_admin_only_and_noindex(): void
    {
        $p = $this->editorial(['status' => 'draft']);
        $this->actingAs($this->admin())->get('/admin/editorial/posts/'.$p->id.'/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_seo_uses_clean_canonical_and_escaped_title(): void
    {
        $this->editorial(['title' => 'Notícia <especial>', 'excerpt' => 'Resumo seguro']);
        // Canonical sai de APP_URL (no CI é http://localhost:8080, vindo do .env.example).
        $canonical = rtrim(config('app.url'), '/').'/blog/teste';
        $this->get('/blog/teste?utm_source=evil')->assertOk()->assertSee('property="og:type" content="article"', false)->assertSee('Notícia &lt;especial&gt;', false)->assertSee('href="'.$canonical.'"', false)->assertDontSee('content="'.$canonical.'?utm_source', false);
    }

    public function test_feed_and_post_expose_readable_category_counts_and_related(): void
    {
        Category::create(['name' => 'Eletroportáteis e Casa', 'slug' => 'eletroportateis-e-casa']);
        $old = $this->editorial(['slug' => 'antiga', 'category' => 'eletroportateis-e-casa', 'published_at' => now()->subDays(2)]);
        $new = $this->editorial(['slug' => 'nova', 'category' => 'sem-cadastro', 'published_at' => now()->subHour()]);
        $reader = User::factory()->create();
        DB::table('editorial_comments')->insert([
            ['post_id' => $old->id, 'user_id' => $reader->id, 'display_name' => 'Ana', 'body' => 'Gostei', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()],
            ['post_id' => $old->id, 'user_id' => $reader->id, 'display_name' => 'Ana', 'body' => 'Pendente', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->get('/blog')->assertInertia(fn ($p) => $p
            ->where('posts.0.slug', 'nova')->where('posts.0.category_name', 'Sem Cadastro')
            ->where('posts.1.slug', 'antiga')->where('posts.1.category_name', 'Eletroportáteis e Casa')->where('posts.1.comments_count', 1)
            ->has('categories', 2)->where('categories.0.count', 1));
        $this->get('/blog/antiga')->assertInertia(fn ($p) => $p
            ->where('post.category_name', 'Eletroportáteis e Casa')->where('post.comments_count', 1)
            ->has('related', 1)->where('related.0.slug', 'nova')->missing('related.0.body'));
    }
}
