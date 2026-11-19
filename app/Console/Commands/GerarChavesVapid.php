<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Push\WebPush;
use Illuminate\Console\Command;

/** Par de chaves VAPID para o .env (apps de vendas, 2026-11-19). Não grava nada. */
class GerarChavesVapid extends Command
{
    protected $signature = 'push:gerar-chaves-vapid';

    protected $description = 'Gera VAPID_PUBLIC_KEY e VAPID_PRIVATE_KEY para o Web Push dos apps de vendas';

    public function handle(): int
    {
        $chaves = WebPush::gerarChaves();
        $this->line('VAPID_PUBLIC_KEY='.$chaves['publica']);
        $this->line('VAPID_PRIVATE_KEY='.$chaves['privada']);
        $this->line('VAPID_SUBJECT=mailto:'.config('loja.email_contato'));

        return self::SUCCESS;
    }
}
