<?php

namespace App\Modules\Checkout\Support;

use RuntimeException;

/**
 * O CPF informado pelo visitante no checkout já pertence a uma conta (com
 * outro e-mail). BUG REAL 2026-10-12: sem isso o insert estourava
 * users_cpf_unique e o cliente via erro 500. Classe própria pelo mesmo
 * motivo da GuestEmailAlreadyExistsException.
 */
class GuestCpfAlreadyExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('cpf_exists');
    }
}
