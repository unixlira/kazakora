<?php

namespace App\Modules\Infoprodutos\Support;

use App\Modules\Catalog\Models\OutroSiteSlugAntigo;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductSlugAntigo;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Página de produto com endereço antigo (pedido do Lira em 2026-11-26): os
 * slugs da FerraKora mudaram (saiu o "pdf-gratis") e as visitas e cliques
 * antigos ficaram em fk/<slug antigo>, separados do produto. Aqui a chave
 * antiga aponta para a atual, para a tela Comportamento somar o histórico:
 *  - kz/<antigo> => kz/<atual> (product_slugs_antigos);
 *  - fk/<antigo> => fk/<atual> (outro_site_slugs_antigos, site ferrakora).
 */
class ChaveAtualDaPagina
{
    private const CACHE = 'comportamento:chaves-antigas';

    /** @var array<string, string>|null */
    private static ?array $mapa = null;

    public static function atual(string $produto): string
    {
        return self::mapa()[$produto] ?? $produto;
    }

    /** A chave atual e as antigas que apontam para ela. @return list<string> */
    public static function todas(string $produto): array
    {
        $atual = self::atual($produto);

        return array_values(array_unique([$atual, ...array_keys(array_filter(self::mapa(), fn ($v) => $v === $atual))]));
    }

    public static function esquecer(): void
    {
        self::$mapa = null;
        Cache::forget(self::CACHE);
    }

    /** @return array<string, string> chave antiga => atual */
    public static function mapa(): array
    {
        return self::$mapa ??= rescue(fn () => Cache::remember(self::CACHE, 600, fn () => self::montar()), [], false);
    }

    /** @return array<string, string> */
    private static function montar(): array
    {
        $mapa = [];
        $slugs = Product::query()->withTrashed()->pluck('slug', 'id');
        foreach (ProductSlugAntigo::query()->get(['product_id', 'slug']) as $a) {
            $atual = $slugs[$a->product_id] ?? null;
            if ($atual && $atual !== $a->slug) {
                $mapa['kz/'.$a->slug] = 'kz/'.$atual;
            }
        }

        try {
            $catalogo = FerraKoraProduto::catalogo();
            $publicado = [];
            foreach (FerraKoraProduto::query()->get() as $r) {
                $slug = $catalogo->slugPublicado($r);
                if ($slug) {
                    $publicado[(int) $r->product_id] = $slug;
                }
            }
            foreach (OutroSiteSlugAntigo::query()->where('site', 'ferrakora')->get(['product_id', 'slug']) as $a) {
                $atual = $publicado[(int) $a->product_id] ?? null;
                if ($atual && $atual !== $a->slug) {
                    $mapa['fk/'.$a->slug] = 'fk/'.$atual;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $mapa;
    }
}
