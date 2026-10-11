<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Seções da home (pedido 2026-10-10), nesta ordem: Ofertas do dia, Mais
 * buscados da semana, Utilidades para casa, Últimas novidades e Você também
 * pode gostar. As listas de IDs ficam em cache (a home tem que ser rápida);
 * os produtos são carregados na hora, numa consulta só, pra preço e estoque
 * estarem sempre certos.
 */
class VitrineHome
{
    private const PALAVRAS_CASA = ['casa', 'cozinha', 'organiza', 'utens', 'utilidade', 'limpeza', 'banheiro', 'lavanderia'];

    public function montar(): array
    {
        $ids = [
            'ofertas' => $this->ofertas(),
            'maisBuscados' => Cache::remember('home:mais-buscados', now()->addHour(), fn () => $this->maisBuscados()),
            'utilidades' => Cache::remember('home:utilidades', now()->addHour(), fn () => $this->utilidades()),
            'novidades' => $this->ativos()->latest()->limit(8)->pluck('id')->all(),
            'gostar' => Cache::remember('home:gostar:'.now()->toDateString(), now()->endOfDay(), fn () => $this->ativos()->inRandomOrder()->limit(8)->pluck('id')->all()),
        ];

        $produtos = Product::query()->forCard()->whereIn('id', collect($ids)->flatten()->unique()->all())->get()->keyBy('id');
        $montar = fn (array $lista) => collect($lista)->map(fn ($id) => $produtos->get($id))->filter()->values();

        return [
            'ofertas' => $montar($ids['ofertas']),
            'ofertasTerminamEm' => now()->endOfDay()->toIso8601String(),
            'maisBuscados' => $montar($ids['maisBuscados']),
            'utilidades' => $montar($ids['utilidades']),
            'utilidadesLink' => Cache::remember('home:utilidades-link', now()->addHour(), fn () => $this->categoriasCasa()->first()?->slug),
            'novidades' => $montar($ids['novidades']),
            'gostar' => $montar($ids['gostar']),
        ];
    }

    /** @return list<int> */
    private function ofertas(): array
    {
        $hoje = now()->toDateString();

        // Rede de segurança: se o cron das 00:05 não rodou, escolhe agora (uma vez só).
        if (! OfertaDoDia::query()->whereDate('data', $hoje)->exists()) {
            Cache::lock('ofertas-do-dia:escolher', 60)->get(fn () => Artisan::call('loja:ofertas-do-dia'));
        }

        return OfertaDoDia::query()->whereDate('data', $hoje)->orderBy('posicao')->pluck('product_id')->all();
    }

    /** Produtos mais vistos nos últimos 7 dias (visitas em /produtos/{slug}). */
    private function maisBuscados(): array
    {
        $slugs = SiteVisit::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->where('path', 'like', '/produtos/%')
            ->selectRaw('path, count(*) as total')
            ->groupBy('path')
            ->orderByDesc('total')
            ->limit(30)
            ->pluck('path')
            ->map(fn ($path) => substr($path, strlen('/produtos/')))
            ->all();

        $porSlug = $this->ativos()->whereIn('slug', $slugs)->pluck('id', 'slug');
        $ids = collect($slugs)->map(fn ($slug) => $porSlug[$slug] ?? null)->filter()->unique()->take(10)->values();

        if ($ids->count() < 10) {
            $ids = $ids->concat($this->ativos()->whereNotIn('id', $ids)->latest()->limit(10 - $ids->count())->pluck('id'));
        }

        return $ids->all();
    }

    private function utilidades(): array
    {
        $categorias = $this->categoriasCasa()->pluck('id');

        return $categorias->isEmpty() ? [] : $this->ativos()->whereIn('category_id', $categorias)->latest()->limit(8)->pluck('id')->all();
    }

    private function categoriasCasa()
    {
        return Category::query()
            ->where(fn ($query) => collect(self::PALAVRAS_CASA)->each(fn ($palavra) => $query->orWhere('name', 'like', "%{$palavra}%")))
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)->whereNull('parent_product_id')])
            ->orderByDesc('products_count')
            ->get(['id', 'slug', 'name']);
    }

    private function ativos()
    {
        return Product::query()->where('is_active', true)->whereNull('parent_product_id')->where('stock', '>', 0);
    }
}
