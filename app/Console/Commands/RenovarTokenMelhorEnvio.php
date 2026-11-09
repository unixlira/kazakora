<?php

namespace App\Console\Commands;

use App\Models\MelhorEnvioToken;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ACHADO REAL 2026-11-09 (#3978): o token do Melhor Envio só era renovado
 * quando alguém usava a API. Ficou parado de 30/08 até novembro, o refresh
 * token venceu ("Cannot decrypt the refresh token") e a etiqueta da árvore de
 * Natal não saiu. Agora renova todo dia, antes de vencer, mesmo sem uso, e
 * avisa no sino se a renovação falhar.
 */
class RenovarTokenMelhorEnvio extends Command
{
    protected $signature = 'melhorenvio:renovar-token {--forcar : Renova mesmo longe de vencer}';

    protected $description = 'Renova o token da API do Melhor Envio antes de vencer (diário) e avisa se falhar';

    /** Renova quando faltam até estes dias para o token vencer. */
    public const DIAS_ANTES = 20;

    public function handle(MelhorEnvioAuthService $auth, AlertasAdmin $alertas): int
    {
        $token = MelhorEnvioToken::current();
        if (! $token) {
            $this->info('Nenhuma conta do Melhor Envio conectada.');

            return self::SUCCESS;
        }

        if (! $this->option('forcar') && $token->token_expires_at->gt(now()->addDays(self::DIAS_ANTES))) {
            $this->info('Token do Melhor Envio vale até '.$token->token_expires_at->format('d/m/Y H:i').'.');
            rescue(fn () => $alertas->resolver(AlertasAdmin::MELHOR_ENVIO_CONEXAO, 'token'), report: false);

            return self::SUCCESS;
        }

        try {
            $novo = $auth->refreshToken($token);
        } catch (Throwable $e) {
            $this->error('Falha ao renovar o token do Melhor Envio: '.$e->getMessage());
            Log::channel(config('melhorenvio.log_channel'))->error('melhorenvio.renovar_token_falhou', ['error' => $e->getMessage()]);
            rescue(fn () => $alertas->disparar(
                AlertasAdmin::MELHOR_ENVIO_CONEXAO,
                'token',
                'Melhor Envio desconectado',
                'A renovação do token da API do Melhor Envio falhou ('.$e->getMessage().'). Etiqueta e cotação pelo Melhor Envio param até reconectar.',
            ), report: false);

            return self::FAILURE;
        }

        $this->info('Token do Melhor Envio renovado até '.$novo->token_expires_at->format('d/m/Y H:i').'.');
        rescue(fn () => $alertas->resolver(AlertasAdmin::MELHOR_ENVIO_CONEXAO, 'token'), report: false);

        return self::SUCCESS;
    }
}
