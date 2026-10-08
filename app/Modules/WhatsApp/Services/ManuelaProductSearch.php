<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\MercadoLivreDriver;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Busca de produto pra Manuela (pedido 2026-10-08): o cliente fala do
 * produto que viu ou ouviu, ela procura pelo nome primeiro na tabela de
 * produtos do Kazakora e, se não achar, nos anúncios da loja na Shopee e no
 * Mercado Livre. Antes ela só via uma lista fixa de produtos COM estoque e
 * respondia "não temos" pra webcam e pra caixa de 168 peças, que estavam
 * cadastradas, só que zeradas.
 */
class ManuelaProductSearch
{
    public const ORIGIN_STORE = 'kazakora';

    public const ORIGIN_SHOPEE = 'shopee';

    public const ORIGIN_MERCADO_LIVRE = 'mercado_livre';

    private const MAX_RESULTS = 3;

    private const MIN_SCORE = 0.5;

    private const STOPWORDS = ['a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'com', 'sem', 'para', 'pra', 'pro', 'um', 'uma', 'no', 'na', 'em', 'que', 'tipo', 'esse', 'essa', 'aquele', 'aquela', 'produto', 'voces', 'vcs'];

    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly ShopeeDriver $shopee,
        private readonly MercadoLivreDriver $mercadoLivre,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function searchStore(string $term): array
    {
        $base = rtrim((string) $this->settings->get('store_base_url'), '/');

        $products = Product::query()
            ->with('parent:id,slug,is_active')
            ->where('is_active', true)
            ->get(['id', 'parent_product_id', 'name', 'slug', 'price', 'discount_percentage', 'discount_amount', 'stock'])
            ->map(fn (Product $product) => [
                'origem' => self::ORIGIN_STORE,
                'id' => (string) $product->id,
                'nome' => $product->name,
                'preco' => $this->money($product->final_price),
                'em_estoque' => $product->stock > 0,
                'link' => $base.'/produtos/'.($product->parent?->slug ?? $product->slug),
            ]);

        return $this->rank($term, $products->all());
    }

    /** @return array<int, array<string, mixed>> */
    public function searchMarketplaces(string $term): array
    {
        return $this->rank($term, [...$this->marketplaceItems(self::ORIGIN_SHOPEE), ...$this->marketplaceItems(self::ORIGIN_MERCADO_LIVRE)]);
    }

    /**
     * Tudo o que dá pra contar do produto: é daqui que a Manuela tira a
     * resposta pra dúvida do cliente.
     *
     * @return array<string, mixed>|null
     */
    public function details(string $origin, string $id): ?array
    {
        if ($origin === self::ORIGIN_STORE) {
            $product = Product::query()->with('parent:id,slug,description')->where('is_active', true)->find($id);

            if (! $product) {
                return null;
            }

            $base = rtrim((string) $this->settings->get('store_base_url'), '/');

            return array_filter([
                'origem' => self::ORIGIN_STORE,
                'id' => (string) $product->id,
                'nome' => $product->name,
                'preco' => $this->money($product->final_price),
                'em_estoque' => $product->stock > 0,
                'marca' => $product->brand,
                'modelo' => $product->model,
                'cor' => $product->color,
                'variacao' => $product->variation,
                'descricao' => $this->plain($product->description ?: $product->parent?->description),
                'link' => $base.'/produtos/'.($product->parent?->slug ?? $product->slug),
            ], fn ($value) => $value !== null && $value !== '');
        }

        $item = collect($this->marketplaceItems($origin))->firstWhere('id', $id);

        if (! $item) {
            return null;
        }

        try {
            $content = $this->driver($origin)->fetchItemContent($id);
        } catch (Throwable $exception) {
            Log::warning('manuela.produto_marketplace_sem_detalhe', ['origem' => $origin, 'id' => $id, 'error' => $exception->getMessage()]);
            $content = [];
        }

        return array_filter([
            ...$item,
            'marca' => $content['brand'] ?? null,
            'modelo' => $content['model'] ?? null,
            'cor' => $content['color'] ?? null,
            'descricao' => $this->plain($content['description'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Anúncios ativos da loja no canal. Cache de 1h: a lista muda pouco e a
     * Shopee/ML levam alguns segundos pra devolver tudo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function marketplaceItems(string $origin): array
    {
        return Cache::remember("whatsapp:manuela:anuncios:{$origin}", now()->addHour(), function () use ($origin) {
            $driver = $this->driver($origin);

            if (! $driver->isConfigured()) {
                return [];
            }

            try {
                $items = $driver->fetchOwnItems();
            } catch (Throwable $exception) {
                Log::warning('manuela.anuncios_indisponiveis', ['origem' => $origin, 'error' => $exception->getMessage()]);

                return [];
            }

            return collect($items)
                ->filter(fn ($item) => filled($item['name']))
                ->map(fn ($item) => [
                    'origem' => $origin,
                    'id' => (string) $item['external_id'],
                    'nome' => $item['name'],
                    'preco' => $item['price'] !== null ? $this->money($item['price']) : null,
                    'link' => $item['link'],
                ])
                ->values()
                ->all();
        });
    }

    private function driver(string $origin): ShopeeDriver|MercadoLivreDriver
    {
        return $origin === self::ORIGIN_SHOPEE ? $this->shopee : $this->mercadoLivre;
    }

    /**
     * Os mais parecidos com o que o cliente falou, melhor primeiro. A nota é
     * a fração das palavras do cliente que aparecem no nome do produto
     * (aceita plural e erro de uma letra: "ferramenta" = "ferramentas",
     * "web cam" = "webcam").
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function rank(string $term, array $items): array
    {
        $words = $this->words($term);

        if ($words === []) {
            return [];
        }

        return collect($items)
            ->map(function (array $item) use ($words) {
                $nameWords = $this->words($item['nome']);
                $joined = implode('', $nameWords);
                $hits = collect($words)->filter(fn ($word) => $this->wordMatches($word, $nameWords, $joined));

                // Só número batendo ("168") não é o mesmo produto.
                $score = $hits->contains(fn ($word) => ! is_numeric($word)) ? $hits->count() / count($words) : 0;

                // Cliente separou o que no nome é uma palavra só ("web cam").
                if (count($words) > 1 && str_contains($joined, implode('', $words))) {
                    $score = 1;
                }

                return $item + ['_score' => $score];
            })
            ->filter(fn ($item) => $item['_score'] >= self::MIN_SCORE)
            ->sortBy([['_score', 'desc'], [fn ($item) => strlen($item['nome']), 'asc']])
            ->unique('link')
            ->take(self::MAX_RESULTS)
            ->map(fn ($item) => collect($item)->except('_score')->all())
            ->values()
            ->all();
    }

    /** @param  array<int, string>  $nameWords */
    private function wordMatches(string $word, array $nameWords, string $joined): bool
    {
        if (strlen($word) >= 4 && str_contains($joined, $word)) {
            return true;
        }

        foreach ($nameWords as $nameWord) {
            if ($nameWord === $word) {
                return true;
            }

            if (strlen($word) >= 4 && strlen($nameWord) >= 4 && (str_starts_with($nameWord, $word) || str_starts_with($word, $nameWord))) {
                return true;
            }

            if (strlen($word) >= 5 && ! is_numeric($word) && levenshtein($word, $nameWord) <= 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function words(string $text): array
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text)));

        return collect(explode(' ', (string) $normalized))
            ->filter(fn ($word) => $word !== '' && ! in_array($word, self::STOPWORDS, true) && (strlen($word) > 1 || is_numeric($word)))
            ->unique()
            ->values()
            ->all();
    }

    private function money(float|string $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private function plain(?string $html): ?string
    {
        if (! filled($html)) {
            return null;
        }

        $text = trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $html)))));

        return Str::limit($text, 3000);
    }
}
