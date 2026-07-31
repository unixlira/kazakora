<?php

namespace App\Modules\Checkout\Support;

use RuntimeException;

/**
 * Sinaliza que o e-mail informado pelo visitante no checkout já pertence a
 * uma conta existente. Deliberadamente uma classe própria (não apenas
 * `RuntimeException` genérica) para o controller poder capturar só este
 * caso específico — `Illuminate\Database\QueryException` também estende
 * `RuntimeException`, então um catch amplo acabaria escondendo erros de
 * banco de verdade atrás da mensagem de "e-mail já cadastrado".
 */
class GuestEmailAlreadyExistsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('email_exists');
    }
}
