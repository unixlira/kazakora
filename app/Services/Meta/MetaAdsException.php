<?php

namespace App\Services\Meta;

use RuntimeException;

/**
 * Erro legível vindo da Marketing API do Meta (mensagem já em texto para
 * mostrar na tela). Nunca carrega o token.
 */
class MetaAdsException extends RuntimeException {}
