<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\ChannelWalletBalance;
use Illuminate\Console\Command;
use App\Services\MercadoPago\MercadoPagoWalletService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pedido explícito 2026-08-09/10: saldo disponível pra saque do Mercado
 * Pago/Livre — a API só entrega isso via relatório assíncrono (~15-20min
 * entre pedir e ficar pronto, ver MercadoPagoWalletService), então roda em
 * duas frentes independentes toda vez: (1) processa o relatório mais
 * recente já pronto, se tiver um novo desde a última vez, e (2) pede um
 * relatório novo pra estar pronto na próxima execução. Sem isso ser dois
 * passos separados no tempo, o dashboard teria que segurar a página por
 * minutos esperando — inaceitável numa requisição HTTP normal.
 */
class SyncMercadoPagoWalletBalance extends Command
{
    protected $signature = 'ads:sync-wallet-balance';

    protected $description = 'Processa o relatório de saldo do Mercado Pago mais recente e pede um novo pra próxima vez';

    public function handle(MercadoPagoWalletService $wallet): int
    {
        if (! config('services.mercadopago.access_token')) {
            $this->warn('MERCADOPAGO_ACCESS_TOKEN não configurado — pulando.');

            return self::SUCCESS;
        }

        try {
            $report = $wallet->latestReadyReport();

            if ($report) {
                $balance = $wallet->downloadBalance($report['file_name']);

                if ($balance !== null) {
                    ChannelWalletBalance::query()->updateOrCreate(
                        ['channel' => 'mercado_livre'],
                        ['balance' => $balance, 'balance_as_of' => Carbon::parse($report['date_created'])],
                    );

                    $this->info("Saldo Mercado Pago atualizado: R$ {$balance} (relatório de {$report['date_created']}).");
                } else {
                    $this->warn('Relatório mais recente não trouxe BALANCE_AMOUNT utilizável.');
                }
            } else {
                $this->info('Nenhum relatório pronto ainda (ver próxima execução).');
            }
        } catch (Throwable $exception) {
            $this->error("Falha ao processar relatório de saldo: {$exception->getMessage()}");
        }

        try {
            // Janela de 3 dias é suficiente pra sempre ter pelo menos um
            // movimento recente e capturar o BALANCE_AMOUNT atual — não
            // precisa do histórico inteiro, só do saldo corrente.
            $wallet->requestReport(now()->subDays(3), now());
            $this->info('Novo relatório de saldo pedido pra próxima execução.');
        } catch (Throwable $exception) {
            $this->error("Falha ao pedir novo relatório: {$exception->getMessage()}");
        }

        return self::SUCCESS;
    }
}
