<?php

namespace App\Modules\Checkout\Support;

use RuntimeException;

/**
 * Trava de 2026-10-16: já sob lockForUpdate da linha do cupom, o limite de
 * usos foi atingido por outro pedido. O controller desfaz a transação antes
 * de qualquer cobrança e devolve o cliente ao checkout sem o desconto.
 * Também usada quando o cupom é de uso único e a pessoa já usou (2026-10-25).
 */
class CupomEsgotadoException extends RuntimeException
{
    public function __construct(string $motivo = 'Este cupom já atingiu o limite de usos.')
    {
        parent::__construct($motivo);
    }
}
