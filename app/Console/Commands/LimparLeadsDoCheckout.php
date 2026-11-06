<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\LeadCheckout;
use Illuminate\Console\Command;

/**
 * Prazo de guarda do carrinho abandonado (lead_checkout), decidido pelo Lira
 * em 2026-10-24: 3 meses (config loja.lead_checkout_dias_guarda, padrão 90
 * dias) desde a última interação (updated_at).
 *
 * Aberto: a finalidade (recuperar a compra) acabou, sai do banco.
 * Convertido: também sai. O que importa da venda já está no pedido (cliente,
 * itens, valores), então guardar o lead só repetiria dado pessoal. Apagar é
 * mais simples e seguro do que anonimizar: não sobra nenhum campo para
 * esquecer de limpar.
 *
 * Leads digitados (tabela leads, tela Admin > Leads, 2026-11-06): mesmo prazo
 * e mesma regra.
 */
class LimparLeadsDoCheckout extends Command
{
    protected $signature = 'leads:limpar {--dry-run : Só conta o que seria apagado, sem apagar}';

    protected $description = 'Apaga os leads do carrinho abandonado (abertos e convertidos) sem interação há mais do prazo de guarda (padrão 90 dias)';

    public function handle(): int
    {
        $dias = max(1, (int) config('loja.lead_checkout_dias_guarda', 90));
        $corte = now()->subDays($dias);
        $vencidos = LeadCheckout::query()->where('updated_at', '<', $corte);

        $abertos = (clone $vencidos)->where('status', LeadCheckout::STATUS_ABERTO)->count();
        $convertidos = (clone $vencidos)->where('status', '!=', LeadCheckout::STATUS_ABERTO)->count();

        if ($this->option('dry-run')) {
            $digitados = Lead::query()->where('updated_at', '<', $corte)->count();
            $this->info("Simulação (prazo {$dias} dias, antes de {$corte->format('d/m/Y H:i')}): apagaria {$abertos} abertos e {$convertidos} convertidos, mais {$digitados} da tela Leads. Nada foi apagado.");

            return self::SUCCESS;
        }

        $apagados = $vencidos->delete();
        $digitados = Lead::query()->where('updated_at', '<', $corte)->delete();

        $this->info("Prazo {$dias} dias: {$apagados} leads apagados ({$abertos} abertos, {$convertidos} convertidos) e {$digitados} da tela Leads.");

        return self::SUCCESS;
    }
}
