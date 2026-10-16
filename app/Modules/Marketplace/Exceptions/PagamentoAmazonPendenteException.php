<?php

namespace App\Modules\Marketplace\Exceptions;

use RuntimeException;

/**
 * Etiqueta segurada porque o pagamento ainda não foi confirmado na Amazon
 * (ver PagamentoAmazon). Não adianta tentar de novo: o envio é disparado
 * outra vez quando o pedido virar "pago" (OrderImportService::syncStatus()).
 */
class PagamentoAmazonPendenteException extends RuntimeException
{
}
