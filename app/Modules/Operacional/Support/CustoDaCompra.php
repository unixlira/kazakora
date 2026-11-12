<?php

namespace App\Modules\Operacional\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Operacional\Models\PurchaseOrder;
use App\Modules\Operacional\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Custo real da compra (pedido do Lira em 12/11/2026).
 *
 * Custo efetivo por unidade = valor unitário pago + rateio do frete e dos
 * outros custos da compra (taxa, imposto de importação), proporcional ao
 * valor de cada item. Compra só com itens de valor zero: o rateio vai pela
 * quantidade.
 *
 * O custo do produto (products.cost_price) só muda pelo botão "Atualizar
 * custo do produto" (aplicar()): o item guarda o custo anterior, quando e
 * quem aplicou. Item que é o produto PAI leva o custo também para todas as
 * variações (cada variação tem o próprio cost_price, que é o que a venda
 * usa em ContributionMargin::custoSql).
 */
class CustoDaCompra
{
    /**
     * @param  list<array{quantity: int|float, unit_cost: int|float}>  $itens
     * @return list<float> custo efetivo por unidade, na ordem dos itens
     */
    public static function ratear(array $itens, float $frete, float $outros): array
    {
        $extras = max(0.0, $frete) + max(0.0, $outros);
        $valorItens = array_sum(array_map(fn ($i) => (float) $i['quantity'] * (float) $i['unit_cost'], $itens));
        $quantidade = array_sum(array_map(fn ($i) => (float) $i['quantity'], $itens));

        return array_map(function ($i) use ($extras, $valorItens, $quantidade) {
            $unit = (float) $i['unit_cost'];
            $q = (float) $i['quantity'];
            if ($q <= 0) {
                return round($unit, 4);
            }
            $parte = match (true) {
                $valorItens > 0 => $extras * ($q * $unit) / $valorItens,
                $quantidade > 0 => $extras * $q / $quantidade,
                default => 0.0,
            };

            return round($unit + $parte / $q, 4);
        }, $itens);
    }

    /** Recalcula e grava o custo efetivo de todos os itens da compra. */
    public static function recalcular(PurchaseOrder $compra): void
    {
        $itens = $compra->items()->orderBy('id')->get();
        $custos = self::ratear(
            $itens->map(fn (PurchaseOrderItem $i) => ['quantity' => $i->quantity, 'unit_cost' => (float) $i->unit_cost])->all(),
            (float) $compra->freight,
            (float) $compra->other_costs,
        );

        foreach ($itens->values() as $n => $item) {
            $item->update(['effective_unit_cost' => $custos[$n]]);
        }
    }

    /**
     * Grava o custo efetivo dos itens escolhidos (todos, sem lista) no
     * cadastro do produto. Devolve quantos produtos mudaram.
     *
     * @param  list<int>|null  $itemIds
     */
    public static function aplicar(PurchaseOrder $compra, ?array $itemIds, User $quem): int
    {
        return DB::transaction(function () use ($compra, $itemIds, $quem) {
            $mudou = 0;
            $itens = $compra->items()->with('product')
                ->when($itemIds, fn ($q) => $q->whereIn('id', $itemIds))
                ->get();

            foreach ($itens as $item) {
                $produto = $item->product;
                if (! $produto || $item->effective_unit_cost === null) {
                    continue;
                }

                $custo = round((float) $item->effective_unit_cost, 2);
                $item->update([
                    'previous_cost' => $produto->cost_price,
                    'cost_applied_at' => now(),
                    'cost_applied_by' => $quem->id,
                ]);
                $produto->update(['cost_price' => $custo]);
                Product::query()->where('parent_product_id', $produto->id)->get()
                    ->each(fn (Product $variacao) => $variacao->update(['cost_price' => $custo]));
                $mudou++;
            }

            return $mudou;
        });
    }
}
