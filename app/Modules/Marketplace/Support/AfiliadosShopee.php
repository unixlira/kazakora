<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Database\Eloquent\Builder;

/**
 * Afiliados › Shopee (pedido do Lira em 2026-10-26): quais produtos podem
 * ser clonados como anúncio de afiliado e por quanto.
 *
 * Preço do clone = custo × 1,5 ("50% maior que o valor que a gente pagou",
 * para pagar 10% de comissão ao afiliado). SKU do clone = SKU + "-AF".
 *
 * Grupos que o Lira definiu: Bike Spinning mecânica, todos os brinquedos
 * (categoria Brinquedos e Games, mais a máquina de algodão doce), potes
 * herméticos, medidor de pressão e as ferramentas do FerraKora que ainda não
 * estão na Shopee. Só produto principal (variação vai junto com o pai).
 */
class AfiliadosShopee
{
    public const CANAL = 'shopee';

    public const MARKUP = 1.5;

    public const COMISSAO = 0.10;

    public const GRUPOS = [
        'bike' => 'Bike Spinning mecânica',
        'brinquedos' => 'Brinquedos',
        'potes' => 'Potes herméticos',
        'medidor' => 'Medidor de pressão',
        'ferramentas' => 'Ferramentas FerraKora fora da Shopee',
    ];

    public static function preco(?float $custo): ?float
    {
        return $custo > 0 ? round($custo * self::MARKUP, 2) : null;
    }

    public static function skuAfiliado(?string $sku): ?string
    {
        return filled($sku) ? $sku.'-AF' : null;
    }

    /** Produtos de um grupo (ou de todos), só os principais. */
    public function query(?string $grupo = null): Builder
    {
        $query = Product::query()->whereNull('parent_product_id');

        if ($grupo === null) {
            return $query->where(function (Builder $w) {
                foreach (array_keys(self::GRUPOS) as $g) {
                    $w->orWhere(fn (Builder $q) => $this->filtro($q, $g));
                }
            });
        }

        return $query->where(fn (Builder $q) => $this->filtro($q, $grupo));
    }

    /** Grupo de cada produto, na ordem de GRUPOS (o primeiro que bater). */
    public function grupoDe(Product $produto, array $semShopee): ?string
    {
        $nome = mb_strtolower($produto->name);

        return match (true) {
            str_contains($nome, 'spinning') && str_contains($nome, 'mec') => 'bike',
            $produto->category?->name === 'Brinquedos e Games' || (str_contains($nome, 'algod') && str_contains($nome, 'doce')) => 'brinquedos',
            str_contains($nome, 'pote') && str_contains($nome, 'herm') => 'potes',
            str_contains($nome, 'medidor de press') => 'medidor',
            in_array($produto->id, $semShopee, true) => 'ferramentas',
            default => null,
        };
    }

    /** Ids das ferramentas ativas no FerraKora sem anúncio na Shopee. */
    public function semShopee(): array
    {
        return $this->semShopee ??= FerraKoraProduto::query()->where('ativo', true)
            ->whereHas('product', fn (Builder $p) => $p->whereNull('parent_product_id')
                ->whereDoesntHave('channelListings', fn (Builder $l) => $l->where('channel', self::CANAL)->whereNotNull('external_id')))
            ->pluck('product_id')->all();
    }

    private ?array $semShopee = null;

    private function filtro(Builder $q, string $grupo): void
    {
        match ($grupo) {
            'bike' => $q->where('name', 'like', '%spinning%')->where('name', 'like', '%mec%'),
            'brinquedos' => $q->whereHas('category', fn (Builder $c) => $c->where('name', 'Brinquedos e Games'))
                ->orWhere(fn (Builder $w) => $w->where('name', 'like', '%algod%')->where('name', 'like', '%doce%')),
            'potes' => $q->where('name', 'like', '%pote%')->where('name', 'like', '%herm%'),
            'medidor' => $q->where('name', 'like', '%medidor de press%'),
            'ferramentas' => $q->whereIn('id', $this->semShopee()),
            default => $q->whereRaw('1 = 0'),
        };
    }
}
