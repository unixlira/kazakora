<?php

namespace App\Services\Correios\Exceptions;

use RuntimeException;

/**
 * Erro real de comunicação/negócio com a API dos Correios (token rejeitado,
 * pré-postagem recusada, timeout etc.) — distinto de
 * CorreiosNotConfiguredException, que é "nem chegamos a tentar".
 */
class CorreiosException extends RuntimeException
{
}
