<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Support\NomeBrasileiro;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Avaliações do carrossel do checkout (modelo Mavo): o da loja e o dos outros
 * sites (FerraKora e Beauty Kora) pela API de resumo. Só as que existem no
 * banco, nota 4 ou 5 e com texto. Primeiro as dos produtos do carrinho
 * (anúncio pai e variações), completando com as melhores da loja; com foto
 * antes. O nome segue a mesma regra da página do produto (reviewer_display_name).
 *
 * Foto redonda (pedido 2026-10-24): avatar do pacote da vitrine em
 * public/images/avaliacoes/avatares, do mesmo gênero do nome, escolhido
 * pelo id da avaliação (a mesma avaliação mostra sempre o mesmo rosto) e sem
 * repetir rosto no mesmo carrossel enquanto houver outro livre.
 */
class AvaliacoesDoCheckout
{
    public const LIMITE = 8;

    public const AVATARES_FEMININOS = 16;

    public const AVATARES_MASCULINOS = 3;

    /**
     * @param  Collection<int, int>|array<int, int>  $productIds  produtos do carrinho
     * @return list<array{id: int, nota: int, nome: string, texto: string, avatar: string}>
     */
    public function paraProdutos(Collection|array $productIds): array
    {
        $ids = collect($productIds)->filter()->unique();
        $grupos = $ids->isEmpty() ? collect() : Product::query()->whereIn('id', $ids)->get(['id', 'parent_product_id'])
            ->map(fn (Product $product) => $product->parent_product_id ?: $product->id)->unique()->values();

        $base = fn () => Review::query()
            ->where('rating', '>=', 4)
            ->whereNotNull('comment')
            ->whereRaw('LENGTH(TRIM(comment)) >= 15')
            ->with(['user:id,name', 'images'])
            ->withCount('images')
            ->orderByRaw('images_count > 0 desc')
            ->orderByDesc('rating')
            ->latest()
            ->orderByDesc('id');

        $avaliacoes = $grupos->isEmpty() ? collect() : $base()
            ->whereIn('product_id', Product::query()->whereIn('id', $grupos)->orWhereIn('parent_product_id', $grupos)->select('id'))
            ->limit(self::LIMITE)
            ->get();

        if ($avaliacoes->count() < self::LIMITE) {
            $avaliacoes = $avaliacoes->concat($base()->whereNotIn('id', $avaliacoes->pluck('id'))->limit(self::LIMITE - $avaliacoes->count())->get());
        }

        $usados = [];

        return $avaliacoes->map(function (Review $review) use (&$usados) {
            if ($review->ocultar_nome) {
                $review->setRelation('user', null);
            }
            $nome = $review->reviewer_display_name;

            return [
                'id' => $review->id,
                'nota' => (int) $review->rating,
                'nome' => $nome,
                'texto' => Str::limit(trim((string) $review->comment), 220),
                'avatar' => asset('images/avaliacoes/avatares/'.self::avatar($review->id, $nome, $usados)),
            ];
        })->values()->all();
    }

    /**
     * Arquivo do avatar ("f03.webp"). $usados guarda os já mostrados no
     * carrossel; quando todos do gênero já saíram, volta a repetir.
     *
     * @param  array<string, true>  $usados
     */
    public static function avatar(int $semente, string $nome, array &$usados = []): string
    {
        [$prefixo, $total] = NomeBrasileiro::feminino($nome)
            ? ['f', self::AVATARES_FEMININOS]
            : ['m', self::AVATARES_MASCULINOS];

        $inicio = hexdec(substr(sha1('avatar-avaliacao:'.$semente), 0, 8)) % $total;
        // Todos do gênero já saíram: começa outra rodada (revezando, sem cair sempre no mesmo).
        if (count(array_filter(array_keys($usados), fn (string $usado) => $usado[0] === $prefixo)) >= $total) {
            $usados = array_filter($usados, fn (string $usado) => $usado[0] !== $prefixo, ARRAY_FILTER_USE_KEY);
        }
        $arquivo = null;
        for ($passo = 0; $passo < $total; $passo++) {
            $candidato = sprintf('%s%02d.webp', $prefixo, (($inicio + $passo) % $total) + 1);
            if (! isset($usados[$candidato])) {
                $arquivo = $candidato;
                break;
            }
        }
        $usados[$arquivo] = true;

        return $arquivo;
    }
}
