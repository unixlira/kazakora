<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Auditoria/reconciliação assíncrona dos pedidos dos últimos N dias em
 * todos os marketplaces conectados — pedido explícito 2026-08-15, depois do
 * bug real de faturamento contando frete como receita (ver
 * FinancialDashboardController). Não recalcula nada por conta própria: só
 * reaproveita os comandos orders:sync-* já existentes e idempotentes —
 * OrderImportService::import() já detecta pedido existente (origin +
 * external_order_id) e corrige subtotal/shipping_cost/total quando
 * divergem do que o canal tem hoje, sem duplicar pedido nem debitar
 * estoque de novo (mesmo mecanismo que a sincronização normal usa). Rodar
 * isso só varre um período maior (90 dias por padrão) de uma vez, em
 * background, sem travar a fila de impressão nem a requisição HTTP que
 * disparou o pedido.
 *
 * Cada canal roda isolado — token expirado ou rate limit num canal não
 * derruba a auditoria dos outros, só fica registrado no log e pode ser
 * re-disparado sozinho depois (orders:sync-shopee/mercadolivre/amazon
 * aceitam --desde/--ate na mão, sem precisar rodar os outros de novo).
 */
class AuditMarketplaceOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 90 dias de Shopee (get_order_list em fatias de 15 dias) + Mercado Livre pode levar bastante chamada de API. */
    public int $timeout = 1800;

    /** Idempotente, mas um retry automático reprocessaria os 90 dias inteiros de novo só por causa de 1 canal que falhou — melhor falhar visível e deixar redisparar na mão. */
    public int $tries = 1;

    public function __construct(
        public readonly int $days = 90,
    ) {
    }

    public function handle(): void
    {
        $from = Carbon::today()->subDays($this->days)->toDateString();
        $to = Carbon::today()->toDateString();

        Log::info('marketplace.audit.started', ['from' => $from, 'to' => $to, 'days' => $this->days]);

        foreach ($this->channelCommands() as $channel => $command) {
            $connected = MarketplaceAccount::query()->where('channel', $channel)->first()?->isConnected();

            if (! $connected) {
                Log::info('marketplace.audit.channel_skipped_not_connected', ['channel' => $channel]);

                continue;
            }

            try {
                $exitCode = Artisan::call($command, ['--desde' => $from, '--ate' => $to]);

                Log::info('marketplace.audit.channel_done', [
                    'channel' => $channel,
                    'exit_code' => $exitCode,
                    'output' => trim(Artisan::output()),
                ]);
            } catch (Throwable $exception) {
                Log::error('marketplace.audit.channel_failed', [
                    'channel' => $channel,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        Log::info('marketplace.audit.finished', ['from' => $from, 'to' => $to]);
    }

    /** @return array<string, string> canal => nome do artisan command (só canais com comando orders:sync-* real) */
    private function channelCommands(): array
    {
        return [
            MarketplaceAccount::CHANNEL_SHOPEE => 'orders:sync-shopee',
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'orders:sync-mercadolivre',
            MarketplaceAccount::CHANNEL_AMAZON => 'orders:sync-amazon',
        ];
    }
}
