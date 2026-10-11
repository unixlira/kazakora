<?php

namespace App\Console\Commands;

use App\Modules\Fiscal\Services\DevolucaoFiscalAutomatica;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use Illuminate\Console\Command;

/**
 * Passa os casos de devolução em aberto pela nota de devolução automática
 * (2026-10-11) — para os casos que já existiam antes dela.
 */
class PrepararNotasDeDevolucao extends Command
{
    protected $signature = 'devolucoes:preparar-notas
        {--emitir : Também emite as dos casos em que o produto já chegou}
        {--reemitir : Devoluções emitidas que ficaram sem nota: completa o NCM pelo XML da venda e manda de novo}';

    protected $description = 'Prepara/emite a NF-e de devolução dos casos de devolução em aberto';

    public function handle(DevolucaoFiscalAutomatica $servico, \App\Modules\Fiscal\Services\SalesReturnService $devolucoes): int
    {
        if ($this->option('reemitir')) {
            $casos = MarketplaceReturn::query()->where('devolucao_fiscal_status', DevolucaoFiscalAutomatica::EMITIDA)->whereNotNull('devolucao_order_id')->get();
            foreach ($casos as $caso) {
                $pedido = \App\Modules\Checkout\Models\Order::query()->with('invoice')->find($caso->devolucao_order_id);
                if (! $pedido || $pedido->invoice) {
                    continue;
                }
                $n = $devolucoes->completarFiscalPeloXml($pedido);
                \App\Modules\Fiscal\Jobs\GenerateInvoiceJob::dispatch($pedido->id);
                $this->line("caso #{$caso->id} -> devolução #{$pedido->id}: {$n} item(ns) completado(s) pelo XML, reenviada");
            }

            return self::SUCCESS;
        }

        $casos = MarketplaceReturn::query()
            ->where('kind', MarketplaceReturn::KIND_DEVOLUCAO)
            ->whereIn('situacao', [...MarketplaceReturn::EM_ABERTO, MarketplaceReturn::CONFERIDA])
            ->get();

        foreach ($casos as $caso) {
            if ($this->option('emitir')) {
                $servico->processar($caso);
            } elseif (! $caso->devolucao_order_id && $caso->devolucao_fiscal_status === null) {
                $servico->preparar($caso);
            }
            $caso->refresh();
            $this->line("#{$caso->id} {$caso->channel} {$caso->external_id} ({$caso->situacao}): {$caso->devolucao_fiscal_status} — {$caso->devolucao_fiscal_obs}");
        }

        $this->info("{$casos->count()} caso(s).");

        return self::SUCCESS;
    }
}
