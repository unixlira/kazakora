<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Editorial\Models\EditorialPost;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Blog do site FerraKora (pedido do Lira, 23/11/2026). As matérias ficam na
 * tabela do blog da KazaKora (editorial_posts) com site = 'ferrakora' e são
 * escritas no mesmo admin (/admin/editorial/posts). Só as publicadas saem.
 *
 * O corpo é texto puro (o admin recusa HTML): o site mostra como texto.
 * Produtos relacionados vêm no formato da vitrine (GET /produtos) e só os
 * que estão no site; sem produto escolhido na matéria, os da categoria.
 */
class FerraKoraBlogController extends Controller
{
    private const POR_PAGINA = 9;

    private const PRODUTOS_RELACIONADOS = 8;

    /** @var array<string, string>|null slug da categoria do site => nome */
    private ?array $categoriasDoSite = null;

    public function __construct(private readonly CatalogoFerraKora $catalogo) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'categoria' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:10000',
        ]);

        $query = $this->publicadas();
        if (! empty($filtros['categoria'])) {
            $query->where('category', $filtros['categoria']);
        }

        $total = (clone $query)->count();
        $ultima = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min((int) ($filtros['page'] ?? 1), $ultima);

        $posts = $query->orderByDesc('published_at')->orderByDesc('id')
            ->forPage($pagina, self::POR_PAGINA)->get()
            ->map(fn (EditorialPost $post) => $this->cartao($post))->values()->all();

        return $this->comCache(response()->json([
            'posts' => $posts,
            'total' => $total,
            'pagination' => ['current' => $pagina, 'last' => $ultima, 'per_page' => self::POR_PAGINA],
            'categories' => $this->categorias(),
        ]));
    }

    public function show(string $slug): JsonResponse
    {
        $post = $this->publicadas()->where('slug', $slug)->first();

        if ($post === null) {
            return response()->json(['message' => 'Matéria não encontrada.'], 404);
        }

        // Preço dos produtos igual ao da vitrine (frete embutido da região).
        app(FreteEmbutido::class)->ligar();

        $relacionadas = $this->publicadas()->where('id', '!=', $post->id)
            ->orderByRaw('category = ? desc', [$post->category])
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(3)->get()
            ->map(fn (EditorialPost $outra) => $this->cartao($outra))->values()->all();

        $resposta = response()->json([
            'post' => [
                ...$this->cartao($post),
                'body' => (string) $post->body,
                'video_url' => $this->absoluta($post->video_url),
                'source_url' => $post->source_url,
                'source_name' => $post->source_name,
                'updated_at' => $post->updated_at?->toIso8601String(),
            ],
            'products' => $this->produtosRelacionados($post),
            'related' => $relacionadas,
        ]);
        // O preço muda com a região do visitante: nenhum cache guarda.
        $resposta->headers->set('Cache-Control', 'private, no-store');
        $resposta->setVary(['CF-Region-Code', 'CF-IPCountry'], false);

        return $resposta;
    }

    private function publicadas()
    {
        return EditorialPost::visible()->doSite(EditorialPost::FERRAKORA);
    }

    /** Cartão da lista (sem o corpo). */
    private function cartao(EditorialPost $post): array
    {
        return [
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'type' => $post->type,
            'category' => $post->category,
            'category_name' => $this->nomeDaCategoria($post->category),
            'poster_url' => $this->absoluta($post->poster_url),
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }

    /** Mídia gravada como caminho da KazaKora (/storage/...) vira endereço completo: o site é outro domínio. */
    private function absoluta(?string $caminho): ?string
    {
        if ($caminho === null || $caminho === '') {
            return null;
        }

        return str_starts_with($caminho, '/') && ! str_starts_with($caminho, '//') ? url($caminho) : $caminho;
    }

    /** @return array<int, array{slug: string, name: string, count: int}> categorias com matéria publicada */
    private function categorias(): array
    {
        return $this->publicadas()->selectRaw('category, count(*) as total')->groupBy('category')
            ->orderByDesc('total')->orderBy('category')->limit(20)->get()
            ->map(fn ($linha) => ['slug' => $linha->category, 'name' => $this->nomeDaCategoria($linha->category), 'count' => (int) $linha->total])
            ->all();
    }

    /** Nome como o menu do site mostra ("Ferramentas Elétricas"), a partir do slug. */
    private function nomeDaCategoria(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $this->categoriasDoSite ??= FerraKoraProduto::query()->where('ativo', true)->whereNotNull('categoria')
            ->distinct()->pluck('categoria')
            ->mapWithKeys(fn ($nome) => [Str::slug((string) $nome) => (string) $nome])->all();

        return $this->categoriasDoSite[$slug] ?? mb_convert_case(str_replace('-', ' ', $slug), MB_CASE_TITLE);
    }

    /** @return array<int, array<string, mixed>> produtos da matéria que estão na vitrine do site */
    private function produtosRelacionados(EditorialPost $post): array
    {
        $escolhidos = $post->products()->pluck('products.id')
            ->map(fn ($id) => CatalogoFerraKora::PREFIXO_PRODUTO.$id)->all();

        $vitrine = collect($this->catalogo->produtos());
        $produtos = $escolhidos !== []
            ? $vitrine->filter(fn (array $produto) => in_array($produto['id'], $escolhidos, true))
                ->sortBy(fn (array $produto) => array_search($produto['id'], $escolhidos, true))
            : $vitrine->filter(fn (array $produto) => filled($produto['categoria'] ?? null) && Str::slug((string) $produto['categoria']) === $post->category);

        return $produtos->take(self::PRODUTOS_RELACIONADOS)
            ->map(fn (array $produto) => Arr::except($produto, ['description', 'media']))
            ->values()->all();
    }

    /** A lista não tem preço: pode ficar 5 minutos em cache. */
    private function comCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->headers->set('Cache-Control', 'public, max-age=300');

        return $resposta;
    }
}
