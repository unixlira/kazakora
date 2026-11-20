<?php

namespace App\Modules\Editorial\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Editorial\Models\EditorialPost;
use App\Modules\Editorial\Support\EditorialSafety;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EditorialController extends Controller
{
    private function visible(string $slug): EditorialPost
    {
        return EditorialPost::visible()->where('slug', $slug)->firstOrFail();
    }

    private function products(?EditorialPost $post = null): array
    {
        $q = Product::query()->naKazaKora()->comEstoque();
        if ($post) {
            $ids = $post->products()->pluck('products.id');
            if ($ids->isNotEmpty()) {
                $q->whereIn('products.id', $ids);
            } else {
                $q->whereHas('category', fn ($c) => $c->where('slug', $post->category));
            }
        }

        return $q->forCard()->limit(12)->get()->map(function ($p) {
            $price = $p->preco_vitrine['final_price'] ?? $p->final_price;

            return ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'url' => '/produtos/'.$p->slug, 'image_url' => $p->images->first()?->url, 'price' => (float) $price];
        })->filter(fn ($p) => $p['price'] > 0)->values()->all();
    }

    private function dto(EditorialPost $p): array
    {
        return array_merge($p->only(['id', 'slug', 'title', 'excerpt', 'body', 'type', 'category', 'video_url', 'poster_url', 'source_url', 'source_name']), ['published_at' => $p->published_at?->toIso8601String(), 'products' => $this->products($p), 'likes_count' => DB::table('editorial_likes')->where('post_id', $p->id)->count()]);
    }

    private function adminDto(EditorialPost $p): array
    {
        return array_merge($this->dto($p), $p->only(['status', 'rights_confirmed', 'editorial_checked', 'editor_id', 'reviewer_id']), ['product_ids' => $p->products()->pluck('products.id')->all()]);
    }

    public function index(Request $r)
    {
        $filters = $r->validate(['categoria' => 'nullable|string|max:100', 'tipo' => 'nullable|in:recipe,news,video', 'page' => 'nullable|integer|min:1|max:10000']);
        $query = EditorialPost::visible();
        foreach (['categoria' => 'category', 'tipo' => 'type'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        $page = (int) ($filters['page'] ?? 1);
        $items = $query->orderByDesc('published_at')->orderByDesc('id')->offset(($page - 1) * 16)->limit(17)->get();
        $hasMore = $items->count() > 16;
        $posts = $items->take(16)->map(function ($p) use ($r) {
            $post = $this->dto($p);
            $post['interaction'] = ['canInteract' => (bool) $r->user(), 'liked' => $r->user() ? DB::table('editorial_likes')->where('post_id', $p->id)->where('user_id', $r->user()->id)->exists() : false, 'likesCount' => $post['likes_count'], 'loginUrl' => route('entrar')];
            return $post;
        })->values()->all();
        $params = array_filter(['categoria' => $filters['categoria'] ?? '', 'tipo' => $filters['tipo'] ?? ''], fn ($v) => $v !== null && $v !== '');
        return Inertia::render('Blog/Index', ['posts' => $posts, 'filters' => $params, 'hasMore' => $hasMore, 'nextPageUrl' => $hasMore ? '/blog?'.http_build_query(array_merge($params, ['page' => $page + 1])) : null, 'previousPageUrl' => $page > 1 ? '/blog?'.http_build_query(array_merge($params, ['page' => $page - 1])) : null, 'products' => collect($posts)->pluck('products')->flatten(1)->unique('id')->values()->all()]);
    }

    private function catalogProducts(string $search = '', array $ids = []): array
    {
        $query = Product::query()->naKazaKora()->comEstoque();
        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }
        if ($ids) {
            $query->whereIn('products.id', $ids);
        }
        $items = $query->forCard()->orderBy('products.id')->limit(51)->get();
        return ['products' => $items->take(50)->map(function ($p) {
            return ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'url' => '/produtos/'.$p->slug, 'image_url' => $p->images->first()?->url, 'price' => (float) ($p->preco_vitrine['final_price'] ?? $p->final_price)];
        })->filter(fn ($p) => $p['price'] > 0)->values()->all(), 'hasMore' => $items->count() > 50];
    }

    public function catalog(Request $r)
    {
        $data = $r->validate(['q' => 'nullable|string|max:150']);
        return response()->json($this->catalogProducts(trim($data['q'] ?? '')));
    }

    private function showResponse(Request $r, EditorialPost $p)
    {
        $post = $this->dto($p);

        return Inertia::render('Blog/Show', ['post' => $post, 'products' => $post['products'], 'comments' => DB::table('editorial_comments')->where('post_id', $p->id)->where('status', 'approved')->orderBy('id')->limit(100)->get(['id', 'display_name', 'body', 'created_at'])->map(fn ($c) => ['id' => $c->id, 'display_name' => $c->display_name, 'body' => $c->body, 'created_at' => Carbon::parse($c->created_at)->toIso8601String()])->all(), 'interaction' => ['canInteract' => (bool) $r->user(), 'liked' => $r->user() ? DB::table('editorial_likes')->where('post_id', $p->id)->where('user_id', $r->user()->id)->exists() : false, 'likesCount' => $post['likes_count'], 'loginUrl' => route('entrar')]]);
    }

    public function show(Request $r, string $slug)
    {
        return $this->showResponse($r, $this->visible($slug));
    }

    public function preview(Request $r, EditorialPost $post)
    {
        return $this->showResponse($r, $post)->toResponse($r)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function like(Request $r, string $slug)
    {
        $p = $this->visible($slug);
        $q = DB::table('editorial_likes')->where('post_id', $p->id)->where('user_id', $r->user()->id);
        if ($r->isMethod('put')) {
            DB::table('editorial_likes')->insertOrIgnore(['post_id' => $p->id, 'user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $q->delete();
        }

        return response()->json(['liked' => $q->exists(), 'likesCount' => DB::table('editorial_likes')->where('post_id', $p->id)->count()]);
    }

    public function comment(Request $r, string $slug)
    {
        $p = $this->visible($slug);
        $data = $r->validate(['body' => 'required|string|min:3|max:2000']);
        $body = EditorialSafety::comment($data['body']);
        if ($body === null || mb_strlen($body) < 3) {
            throw ValidationException::withMessages(['body' => 'Use texto respeitoso, sem HTML ou links. Todo envio passa por revisão humana.']);
        } DB::table('editorial_comments')->insert(['post_id' => $p->id, 'user_id' => $r->user()->id, 'display_name' => mb_substr($r->user()->name, 0, 255), 'body' => $body, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['status' => 'pending'], 201);
    }

    public function adminIndex()
    {
        return Inertia::render('Admin/Editorial/Index', ['posts' => EditorialPost::latest()->limit(100)->get()->map(fn ($p) => $this->adminDto($p))->all()]);
    }

    public function create()
    {
        return Inertia::render('Admin/Editorial/Edit', ['post' => null, 'products' => $this->products()]);
    }

    public function edit(EditorialPost $post)
    {
        return Inertia::render('Admin/Editorial/Edit', ['post' => $this->adminDto($post), 'products' => $this->catalogProducts()['products'], 'selectedProducts' => $post->products()->get(['products.id', 'products.name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all()]);
    }

    private function validated(Request $r, ?EditorialPost $post = null): array
    {
        $d = $r->validate(['slug' => ['required', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('editorial_posts')->ignore($post?->id)], 'title' => 'required|string|max:255', 'excerpt' => 'nullable|string|max:1000', 'body' => 'required|string|max:100000', 'type' => 'required|in:video,news,recipe', 'category' => 'required|string|max:100', 'status' => 'required|in:draft,review', 'published_at' => 'nullable|date', 'video_url' => 'nullable|string|max:2048', 'poster_url' => 'nullable|string|max:2048', 'source_url' => 'nullable|url:https|max:2048', 'source_name' => 'nullable|string|max:255', 'rights_confirmed' => 'required|boolean', 'editorial_checked' => 'required|boolean', 'product_ids' => 'sometimes|array|max:30', 'product_ids.*' => 'integer|distinct|exists:products,id']);
        foreach (['video_url' => 'video', 'poster_url' => 'image'] as $field => $kind) {
            if (! EditorialSafety::media($d[$field] ?? null, $kind)) {
                throw ValidationException::withMessages([$field => 'Somente mídia própria HTTPS ou caminho relativo seguro.']);
            }
        }
        if (strip_tags($d['body']) !== $d['body']) {
            throw ValidationException::withMessages(['body' => 'O corpo deve ser texto puro, sem HTML.']);
        }

        return $d;
    }

    public function store(Request $r)
    {
        $d = $this->validated($r);
        $ids = $d['product_ids'] ?? [];
        unset($d['product_ids']);
        $p = DB::transaction(function () use ($d, $ids, $r) {
            $p = EditorialPost::create(array_merge($d, ['editor_id' => $r->user()->id]));
            $p->products()->sync($ids);

            return $p;
        });

        return redirect('/admin/editorial/posts/'.$p->id.'/edit');
    }

    public function update(Request $r, EditorialPost $post)
    {
        $d = $this->validated($r, $post);
        $ids = $d['product_ids'] ?? [];
        unset($d['product_ids']);
        DB::transaction(function () use ($d, $ids, $r, $post) {
            $post->update(array_merge($d, ['status' => $post->status === 'published' ? 'draft' : $d['status'], 'editor_id' => $r->user()->id, 'reviewer_id' => null]));
            $post->products()->sync($ids);
        });

        return redirect('/admin/editorial/posts/'.$post->id.'/edit');
    }

    public function publish(Request $r, EditorialPost $post)
    {
        if ($post->type === 'video' && ! $post->video_url) {
            throw ValidationException::withMessages(['video_url' => 'Informe o vídeo antes de publicar este tipo de conteúdo.']);
        }
        if (! $post->rights_confirmed || ! $post->editorial_checked) {
            throw ValidationException::withMessages(['rights_confirmed' => 'Confirme direitos da mídia e checagem editorial antes de publicar.']);
        } $post->update(['status' => 'published', 'published_at' => $post->published_at ?? now(), 'reviewer_id' => $r->user()->id]);

        return back();
    }

    public function unpublish(Request $r, EditorialPost $post)
    {
        $post->update(['status' => 'draft', 'editor_id' => $r->user()->id, 'reviewer_id' => null]);

        return back();
    }

    public function destroy(EditorialPost $post)
    {
        $post->delete();

        return redirect('/admin/editorial/posts');
    }

    public function comments()
    {
        return Inertia::render('Admin/Editorial/Moderation', ['comments' => DB::table('editorial_comments')->latest('id')->limit(100)->get(['id', 'post_id', 'display_name', 'body', 'status', 'created_at', 'moderator_id', 'moderated_at'])->all()]);
    }

    public function moderate(Request $r, int $comment)
    {
        abort_unless(DB::table('editorial_comments')->where('id', $comment)->exists(), 404);
        DB::table('editorial_comments')->where('id', $comment)->update(['status' => $r->routeIs('editorial.comments.approve') ? 'approved' : 'rejected', 'moderator_id' => $r->user()->id, 'moderated_at' => now(), 'updated_at' => now()]);

        return back();
    }
}
