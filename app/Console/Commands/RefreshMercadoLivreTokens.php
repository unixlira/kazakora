<?php

namespace App\Console\Commands;

use App\Models\MercadoLivreToken;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshMercadoLivreTokens extends Command
{
    protected $signature = 'mercadolivre:refresh-tokens';

    protected $description = 'Renova os tokens do Mercado Livre que expiram em até 1 hora';

    public function handle(MercadoLivreAuthService $auth): int
    {
        $expiringSoon = MercadoLivreToken::query()
            ->where('token_expires_at', '<=', now()->addHour())
            ->get();

        if ($expiringSoon->isEmpty()) {
            $this->info('Nenhum token do Mercado Livre precisa ser renovado.');

            return self::SUCCESS;
        }

        foreach ($expiringSoon as $token) {
            try {
                $auth->refreshToken($token);
                $this->info("Token renovado: ml_user_id={$token->ml_user_id}");
            } catch (Throwable $exception) {
                $this->error("Falha ao renovar token ml_user_id={$token->ml_user_id}: {$exception->getMessage()}");

                Log::channel(config('mercadolivre.log_channel'))->error('mercadolivre.refresh_command_failed', [
                    'ml_user_id' => $token->ml_user_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
