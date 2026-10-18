<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\ImportadorDemonstrativoTikTok;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Tira do extrato do TikTok as linhas repetidas que dobravam receita e taxa
 * de pedido (validação financeira 2026-10-18, ver
 * ImportadorDemonstrativoTikTok::removerLinhasSuperadas) e refaz a taxa real
 * dos pedidos afetados. Guarda as linhas removidas em JSON antes de apagar.
 */
class LimparExtratoTikTok extends Command
{
    protected $signature = 'tiktok:limpar-extrato {--seco : só mostra o que sairia, sem apagar}';

    protected $description = 'Remove linhas repetidas do extrato do TikTok e refaz a taxa real dos pedidos afetados';

    public function handle(ImportadorDemonstrativoTikTok $importador): int
    {
        $seco = (bool) $this->option('seco');
        $previa = $importador->removerLinhasSuperadas(null, seco: true);

        $linhas = DB::table('marketplace_settlement_details')->whereIn('id', $previa['ids'])->get();
        $this->info(sprintf(
            '%d linha(s) repetida(s) em %d pedido(s): vendas líquidas R$ %s, liquidado R$ %s contados a mais.',
            $linhas->count(),
            count($previa['pedidos']),
            number_format((float) $linhas->where('transaction_type', 'Pedido')->sum('product_net_sales'), 2, ',', '.'),
            number_format((float) $linhas->sum('payout_amount'), 2, ',', '.'),
        ));

        if ($seco || $linhas->isEmpty()) {
            return self::SUCCESS;
        }

        $backup = 'backups/tiktok-extrato-linhas-removidas-'.now()->format('Y-m-d-His').'.json';
        Storage::disk('local')->put($backup, $linhas->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $removidas = $importador->removerLinhasSuperadas();
        $taxas = $previa['pedidos'] ? $importador->aplicarTaxasReais($previa['pedidos']) : 0;

        $this->info(count($removidas['ids'])." linha(s) removida(s) (cópia em storage/app/private/{$backup}); taxa real refeita em {$taxas} pedido(s).");

        return self::SUCCESS;
    }
}
