<?php

namespace App\Console\Commands;

use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use Illuminate\Console\Command;

/**
 * Link para o dono da conta do Melhor Envio autorizar a API uma vez
 * (2026-11-09, #3978), sem entrar no admin da KazaKora: abre, clica em
 * Autorizar e o token volta pelo /api/melhorenvio/callback. Vale 10 min.
 * Depois disso o melhorenvio:renovar-token mantém a conexão sozinho.
 */
class LinkAutorizacaoMelhorEnvio extends Command
{
    protected $signature = 'melhorenvio:link-autorizacao';

    protected $description = 'Gera o link (10 min) para autorizar a API do Melhor Envio sem login no admin';

    public function handle(MelhorEnvioAuthService $auth): int
    {
        $this->line($auth->getAuthorizationUrl());

        return self::SUCCESS;
    }
}
