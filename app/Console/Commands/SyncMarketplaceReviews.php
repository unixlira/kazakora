<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\ReviewImportService;
use Illuminate\Console\Command;

/**
 * Pedido explícito 2026-08-16 — cron diário que busca avaliações (nota,
 * comentário, nome do comprador e imagens) em todos os marketplaces
 * conectados e importa pro produto local correspondente. Ver
 * ReviewImportService pro fluxo real; canais sem suporte ainda (ver
 * AbstractMarketplaceDriver::fetchReviews()) são pulados sem erro.
 */
class SyncMarketplaceReviews extends Command
{
    protected $signature = 'reviews:sync';

    protected $description = 'Importa avaliações (nota, comentário, imagens) dos produtos publicados em cada marketplace conectado.';

    public function handle(ReviewImportService $service): int
    {
        $summary = $service->importAll();

        $this->info(sprintf(
            'Avaliações: %d itens consultados, %d novas, %d atualizadas, %d ignoradas por ambiguidade de variação.%s',
            $summary['products_checked'],
            $summary['imported'],
            $summary['updated'],
            $summary['ambiguous_skipped'],
            $summary['channels_skipped'] ? ' Canais sem suporte ainda: '.implode(', ', $summary['channels_skipped']).'.' : '',
        ));

        return self::SUCCESS;
    }
}
