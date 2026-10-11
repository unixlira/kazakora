<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\AutoReplyShopeeReviewsService;
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
    protected $signature = 'reviews:sync
        {--no-auto-reply : Sincroniza/importa avaliações sem responder publicamente na Shopee.}
        {--retry-failed : Tenta novamente avaliações que já falharam antes.}';

    protected $description = 'Importa avaliações dos marketplaces e responde automaticamente avaliações da Shopee.';

    public function handle(ReviewImportService $service, AutoReplyShopeeReviewsService $autoReply): int
    {
        $summary = $service->importAll();
        $replySummary = $this->option('no-auto-reply')
            ? ['checked' => 0, 'sent' => 0, 'failed' => 0]
            : $autoReply->replyPendingPositiveShopeeReviews(retryFailed: (bool) $this->option('retry-failed'));

        $suffix = $this->option('no-auto-reply')
            ? ' Respostas Shopee: desativadas nesta execução de teste.'
            : sprintf(
                ' Respostas Shopee: %d verificadas, %d enviadas, %d falharam.',
                $replySummary['checked'],
                $replySummary['sent'],
                $replySummary['failed'],
            );

        $this->info(sprintf(
            'Avaliações: %d itens consultados, %d novas, %d atualizadas, %d ignoradas por ambiguidade de variação.%s%s',
            $summary['products_checked'],
            $summary['imported'],
            $summary['updated'],
            $summary['ambiguous_skipped'],
            $summary['channels_skipped'] ? ' Canais sem suporte ainda: '.implode(', ', $summary['channels_skipped']).'.' : '',
            $suffix,
        ));

        return self::SUCCESS;
    }
}
