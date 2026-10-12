<?php

namespace App\Services\Kalodata\Exceptions;

use Exception;

/**
 * Erro da integração com o Kalodata. $tipo diz o que fazer:
 *  - config:    falta KALODATA_API_KEY no .env
 *  - auth:      chave recusada (401/403 ou code 501 "The key is not allowed")
 *  - limite:    code 2016 por plano/quota (crédito acabou, período longo demais)
 *  - parametro: code 2016 por parâmetro inválido, ou validação local
 *  - teto:      teto diário de chamadas reais atingido (KALODATA_TETO_DIARIO)
 *  - rede:      sem resposta, ou code 2000 depois de todas as tentativas
 *  - api:       qualquer outro erro do Kalodata
 *
 * $context carrega o corpo cru da resposta (só para log, nunca para o
 * usuário final). A chave nunca entra aqui.
 */
class KalodataException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $tipo = 'api',
        public readonly ?string $codigo = null,
        int $httpStatus = 0,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $httpStatus);
    }
}
