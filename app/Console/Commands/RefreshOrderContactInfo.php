<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill pontual pra pedido de canal externo já importado antes de
 * OrderImportService::resolveBuyerFieldUpdates() passar a atualizar
 * e-mail/telefone (achado real 2026-08-08: um pedido que nasceu sem
 * telefone/e-mail ficava preso nesse estado pra sempre, mesmo que o
 * marketplace já tivesse o dado disponível numa reconsulta). Reprocessa
 * cada pedido via importOrder() de novo e só grava o que já estava
 * vazio/mascarado — nunca sobrescreve um dado bom que já existe (mesma
 * regra de refreshBuyerInfo()).
 *
 * Shopee fica de fora do escopo padrão: nome e telefone do comprador são
 * mascarados pra TODO vendedor, sem exceção nem endpoint de decrypt
 * (confirmado contra o próprio suporte da Shopee) — rodar esse comando pra
 * Shopee é uma chamada real à API deles garantida a não mudar nada. Só
 * roda se pedido explicitamente via --canal=shopee.
 */
class RefreshOrderContactInfo extends Command
{
    protected $signature = 'orders:refresh-contato {--canal= : mercado_livre|amazon|shopee — padrão: mercado_livre e amazon}';

    protected $description = 'Reconsulta pedido de canal externo já importado pra tentar completar telefone/e-mail do cliente que ainda está vazio';

    public function handle(OrderImportService $importer): int
    {
        $canal = $this->option('canal');

        $channels = $canal
            ? [$canal]
            : [MarketplaceAccount::CHANNEL_MERCADO_LIVRE, MarketplaceAccount::CHANNEL_AMAZON];

        if ($canal === MarketplaceAccount::CHANNEL_SHOPEE) {
            $this->warn('Shopee mascara nome/telefone do comprador pra todo vendedor, sem exceção — este comando não vai conseguir completar esses campos pra pedido Shopee. Rodando mesmo assim, a pedido explícito.');
        }

        $orders = Order::query()->whereIn('origin', $channels)->get();

        $this->info(count($orders).' pedido(s) a reconsultar em '.implode(', ', $channels).'...');

        $updated = 0;
        $unchanged = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                if ($importer->refreshContactInfo($order)) {
                    $updated++;
                } else {
                    $unchanged++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("Pedido #{$order->id} ({$order->origin}): {$exception->getMessage()}");
            }
        }

        $this->info("Concluído: {$updated} atualizado(s), {$unchanged} sem mudança, {$failed} com erro.");

        return self::SUCCESS;
    }
}
