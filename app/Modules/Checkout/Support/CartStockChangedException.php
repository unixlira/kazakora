<?php

namespace App\Modules\Checkout\Support;

use RuntimeException;

/**
 * BUG REAL 2026-09-29: sinaliza que, já sob lockForUpdate, o estoque (ou o
 * preço) de algum item não bate mais com o carrinho que foi usado pra
 * calcular o valor cobrado. O controller aborta a criação do pedido inteiro
 * (rollback da transação, antes de qualquer cobrança no gateway) e manda o
 * cliente de volta pro carrinho com esta mensagem.
 */
class CartStockChangedException extends RuntimeException
{
    /**
     * @param  list<string>  $productNames
     */
    public function __construct(public readonly array $productNames)
    {
        parent::__construct(self::customerMessage($productNames));
    }

    /**
     * @param  list<string>  $productNames
     */
    private static function customerMessage(array $productNames): string
    {
        $names = implode(', ', $productNames);

        return count($productNames) > 1
            ? "O estoque dos produtos {$names} mudou, revise seu carrinho antes de finalizar."
            : "O estoque de {$names} mudou, revise seu carrinho antes de finalizar.";
    }
}
