<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Limpeza do sino (pedido 2026-10-18): em out/2026 a tabela notifications
 * tinha 443 mil linhas (376 MB), quase tudo LabelUnavailableNotification de
 * setembro, e deixava o backup horário lento. Lida some com 15 dias; não lida
 * com 30 (aviso de falha com mais de um mês já não é acionável).
 * Apaga em lotes para não segurar a tabela travada.
 */
class LimparNotificacoesAntigas extends Command
{
    protected $signature = 'notificacoes:limpar {--dias-lidas=15} {--dias=30} {--lote=5000}';

    protected $description = 'Apaga notificações lidas com mais de 15 dias e qualquer notificação com mais de 30 dias';

    public function handle(): int
    {
        $lidasAte = now()->subDays((int) $this->option('dias-lidas'));
        $todasAte = now()->subDays((int) $this->option('dias'));
        $lote = max(1, (int) $this->option('lote'));
        $apagadas = 0;

        do {
            $n = DatabaseNotification::query()
                ->where(fn ($query) => $query
                    ->where('created_at', '<', $todasAte)
                    ->orWhere(fn ($lidas) => $lidas->whereNotNull('read_at')->where('created_at', '<', $lidasAte)))
                ->limit($lote)
                ->delete();
            $apagadas += $n;
        } while ($n === $lote);

        $this->info("Notificações apagadas: {$apagadas}.");

        return self::SUCCESS;
    }
}
