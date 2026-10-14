<?php

namespace App\Modules\FerraKora\Services;

use RuntimeException;

/** O total da compra mudou entre a tela do checkout e o pagamento (preço, estoque ou CEP). */
class PrecoMudouException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('O valor da compra mudou. Confira o novo total e confirme de novo.');
    }
}
