<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Comando descartável só pra testar se um cron novo no Hostinger está
 * disparando de verdade (2026-08-06, enquanto investigávamos o cron do
 * queue:work usando o php errado). Manda um e-mail real via o mailer já
 * configurado — serve tanto pra confirmar o cron quanto pra confirmar que
 * o php83 + bootstrap do Laravel funcionam nesse contexto.
 */
class TestCronEmail extends Command
{
    protected $signature = 'cron:test-email';

    protected $description = 'Envia um e-mail de teste pra confirmar que um cron está disparando de verdade';

    public function handle(): int
    {
        Mail::raw('Teste de cron do Kazakora — disparado em '.now()->format('d/m/Y H:i:s'), function ($message) {
            $message->to('joserobertolira@gmail.com')->subject('Teste de cron — Kazakora');
        });

        $this->info('E-mail de teste enviado.');

        return self::SUCCESS;
    }
}
