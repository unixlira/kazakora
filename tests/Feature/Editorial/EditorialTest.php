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
        $this->get('/blog/teste?utm_source=evil')->assertOk()->assertSee('property="og:type" content="article"', false)->assertSee('Notícia &lt;especial&gt;', false)->assertSee('http://localhost/blog/teste', false)->assertDontSee('content="http://localhost/blog/teste?utm_source', false);
    }
}
