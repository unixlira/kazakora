<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\MercadoLivreAdsService;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pedido explícito 2026-08-09: gasto real de anúncio (Shopee Ads + Mercado
 * Ads) pro painel de lucro líquido — confirmado ao vivo que as duas APIs
 * respondem com dado real de custo diário. Sincroniza uma janela (não só
 * "ontem") de propósito: os números de um dia continuam mudando por umas
 * horas depois da meia-noite (a Shopee mesma já mostrou isso nos testes ao
 * vivo — o dia mais recente da série sempre vinha com número parcial),
 * então cada rodada re-sincroniza os últimos dias pra corrigir sozinha.
 */
class SyncChannelAdSpend extends Command
{
    protected $signature = 'ads:sync-spend {--dias=3 : Quantos dias pra trás re-sincronizar, incluindo hoje}';

    protected $description = 'Sincroniza o gasto diário real com anúncio (Shopee Ads + Mercado Ads)';

    public function handle(ShopeeAdsService $shopeeAds, MercadoLivreAdsService $mlAds): int
    {
        $days = max(1, (int) $this->option('dias'));
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $this->syncShopee($shopeeAds, $from, $to);
        $this->syncMercadoLivre($mlAds, $from, $to);

        return self::SUCCESS;
    }

    private function syncShopee(ShopeeAdsService $service, Carbon $from, Carbon $to): void
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();

        if (! $account?->isConnected()) {
            $this->warn('Shopee não conectada — pulando sincronização de anúncio.');

            return;
        }

        try {
            $rows = $service->dailyPerformance($from, $to);

            foreach ($rows as $row) {
                $this->upsert(MarketplaceAccount::CHANNEL_SHOPEE, $row);
            }

            $this->info('Shopee Ads: '.count($rows).' dia(s) sincronizado(s).');
        } catch (Throwable $exception) {
            $this->error("Shopee Ads: falha ao sincronizar — {$exception->getMessage()}");
        }
    }

    private function syncMercadoLivre(MercadoLivreAdsService $service, Carbon $from, Carbon $to): void
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->first();

        if (! $account?->isConnected()) {
            $this->warn('Mercado Livre não conectado — pulando sincronização de anúncio.');

            return;
        }

        $synced = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            try {
                $row = $service->dailySpend($date->copy());

                if ($row === null) {
                    // Conta sem cadastro de anunciante — não é erro, só
                    // nunca criou campanha nenhuma no Mercado Ads.
                    continue;
                }

                $this->upsert(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $row);
                $synced++;
            } catch (Throwable $exception) {
                $this->error("Mercado Ads: falha em {$date->toDateString()} — {$exception->getMessage()}");
            }
        }

        $this->info("Mercado Ads: {$synced} dia(s) sincronizado(s).");
    }

    /**
     * @param  array{date: string, impressions: int, clicks: int, attributed_orders: int, attributed_gmv: float, spend: float}  $row
     */
    private function upsert(string $channel, array $row): void
    {
        ChannelAdSpend::query()->updateOrCreate(
            ['channel' => $channel, 'date' => $row['date']],
            [
                'impressions' => $row['impressions'],
                'clicks' => $row['clicks'],
                'attributed_orders' => $row['attributed_orders'],
                'attributed_gmv' => $row['attributed_gmv'],
                'spend' => $row['spend'],
            ],
        );
    }
}
