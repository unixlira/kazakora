<?php

namespace App\Console\Commands;

use App\Modules\Admin\Support\Indicadores\PainelDeAlertas;
use Illuminate\Console\Command;

/**
 * Os mesmos alertas da tela Indicadores (2026-10-14), para o Homer e a Naia
 * lerem pela linha de comando. Só leitura.
 */
class IndicadoresAlertas extends Command
{
    protected $signature = 'indicadores:alertas {--dias=7 : 7, 14 ou 30 dias completos} {--json : Saída em JSON}';

    protected $description = 'Lista os indicadores de e-commerce fora do limite (ROAS, CTR, CPC, CPA, margem, cancelamento, estoque)';

    public function handle(PainelDeAlertas $painel): int
    {
        $r = $painel->gerar((int) $this->option('dias'));

        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("Indicadores de {$r['desde']} a {$r['ate']} contra {$r['anterior_desde']} a {$r['anterior_ate']}: {$r['resumo']['critico']} crítico(s), {$r['resumo']['atencao']} de atenção.");
        foreach ($r['alertas'] as $a) {
            $this->line(($a['nivel'] === PainelDeAlertas::CRITICO ? '[CRÍTICO] ' : '[atenção] ')."{$a['texto']} {$a['acao']}");
        }

        return self::SUCCESS;
    }
}
