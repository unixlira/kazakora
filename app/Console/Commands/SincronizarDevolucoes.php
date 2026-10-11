<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Modules\Marketplace\Support\ReturnsSyncService;
use Illuminate\Console\Command;

/**
 * Traz devoluções/reclamações do ML e da Shopee pro controle de devoluções
 * e reavalia os alertas (prazo vencendo depende só do relógio) — inclusive
 * dos casos registrados à mão (TikTok/Amazon).
 */
class SincronizarDevolucoes extends Command
{
    protected $signature = 'devolucoes:sincronizar';

    protected $description = 'Sincroniza devoluções e reclamações do Mercado Livre e da Shopee e reavalia os alertas';

    public function handle(ReturnsSyncService $sync): int
    {
        $total = $sync->sincronizar();

        MarketplaceReturn::query()->where('manual', true)->whereIn('situacao', MarketplaceReturn::EM_ABERTO)
            ->each(fn (MarketplaceReturn $caso) => $sync->avisar($caso));

        cache()->forget('devolucoes.contagem_alertas');

        $this->info("Mercado Livre: {$total['mercado_livre']} caso(s) · Shopee: {$total['shopee']} caso(s).");

        return self::SUCCESS;
    }
}
