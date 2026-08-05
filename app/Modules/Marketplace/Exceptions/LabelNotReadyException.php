<?php

namespace App\Modules\Marketplace\Exceptions;

use RuntimeException;

/**
 * Sinaliza "ainda não" pro CheckShipmentLabelJob — não é um erro de
 * verdade, é o gatilho pro Laravel aplicar o backoff de 5s e tentar de
 * novo até retryUntil() vencer (4h corridas desde o primeiro dispatch).
 */
class LabelNotReadyException extends RuntimeException
{
}
