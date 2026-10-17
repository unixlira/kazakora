<?php

namespace App\Modules\Checkout\Support;

use RuntimeException;

/**
 * Trava de 2026-10-16: já sob lockForUpdate da linha do cupom, o limite de
 * usos foi atingido por outro pedido. O controller desfaz a transação antes
 * de qualquer cobrança e devolve o cliente ao checkout sem o desconto.
 */
class CupomEsgotadoException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este cupom já atingiu o limite de usos.');
    }
}
