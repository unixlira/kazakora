<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\ShopeeMetricas\ImportadorShopeeMetricas;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa para shopee_metricas os números da tela do Seller Center da
 * Shopee (Análise Profunda de Conta, 2026-10-26). Aceita arquivos ou
 * pastas (todos os .json de dentro). Só lê arquivo: nada é pedido nem
 * alterado na Shopee.
 *
 *   php artisan shopee:metricas-importar storage/app/shopee-metricas/2026-10-26
 *   php artisan shopee:metricas-importar arquivo.json --simular
 */
class ImportarShopeeMetricas extends Command
{
    protected $signature = 'shopee:metricas-importar {caminhos* : arquivos .json ou pastas}
        {--simular : só conta o que gravaria}';

    protected $description = 'Importa métricas da tela da Shopee (Seller Center) para a tabela shopee_metricas';

    public function handle(ImportadorShopeeMetricas $importador): int
    {
        $arquivos = [];
        foreach ($this->argument('caminhos') as $caminho) {
            if (is_dir($caminho)) {
                $achados = glob(rtrim($caminho, '/').'/*.json') ?: [];
                sort($achados);
                array_push($arquivos, ...$achados);
            } elseif (is_file($caminho)) {
                $arquivos[] = $caminho;
            } else {
                $this->error("Não encontrado: {$caminho}");

                return self::FAILURE;
            }
        }

        $falhou = false;
        $total = 0;
        foreach ($arquivos as $arquivo) {
            try {
                $r = $importador->importar($arquivo, (bool) $this->option('simular'));
                $total += $r['gravadas'];
                $this->line(sprintf('%s [%s, %s]: %d gravadas, %d ignoradas, %d mantidas (coleta mais nova no banco)',
                    $arquivo, $r['formato'], $r['periodo'] ?? 'sem período', $r['gravadas'], $r['ignoradas'], $r['mais_novas_no_banco']));
            } catch (Throwable $e) {
                $falhou = true;
                $this->warn("{$arquivo}: {$e->getMessage()}");
            }
        }

        $this->info(($this->option('simular') ? 'Simulação: ' : '')."{$total} linhas em ".count($arquivos).' arquivo(s).');

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
