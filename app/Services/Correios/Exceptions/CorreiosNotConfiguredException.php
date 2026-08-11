<?php

namespace App\Services\Correios\Exceptions;

/**
 * Credenciais dos Correios (CORREIOS_NUMERO_USUARIO/CORREIOS_CODIGO_ACESSO)
 * ausentes — sinal explícito pro controller pra não gravar isso como uma
 * tentativa real de pré-postagem no histórico, só avisar o que falta.
 */
class CorreiosNotConfiguredException extends CorreiosException
{
}
