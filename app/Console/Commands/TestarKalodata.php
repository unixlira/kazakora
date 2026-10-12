<?php

namespace App\Console\Commands;

use App\Services\Kalodata\Exceptions\KalodataException;
use App\Services\Kalodata\KalodataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Teste de conexão com o Kalodata: saldo de créditos (/credit/usage, sem
 * cache) e uma busca de categoria (com cache). No máximo 2 chamadas reais.
 *
 *   php artisan kalodata:testar
 *   php artisan kalodata:testar --palavra="fone de ouvido"
 */
class TestarKalodata extends Command
{
    protected $signature = 'kalodata:testar {--palavra=power bank : palavra para a busca de categoria} {--regiao= : região (padrão KALODATA_REGIAO)}';

    protected $description = 'Testa a conexão com a API do Kalodata (saldo de créditos e uma busca de categoria)';

    public function handle(KalodataService $kalodata): int
    {
        if (! $kalodata->configurado()) {
            $this->error('ERRO: KALODATA_API_KEY não está no .env.');

            return self::FAILURE;
        }

        $this->line("Chamadas reais hoje: {$kalodata->chamadasHoje()} de {$kalodata->tetoDiario()} (teto diário).");

        try {
            $pacotes = $kalodata->creditos();
            if ($pacotes === []) {
                $this->info('OK /credit/usage: a API respondeu, mas não informou pacotes de crédito (lista vazia).');
            } else {
                $this->info('OK /credit/usage:');
                $this->table(['Tipo', 'Restante', 'Total', 'Validade'], array_map(fn ($p) => [
                    $p['type'] ?? '-',
                    $p['remain'] ?? '-',
                    $p['total'] ?? '-',
                    isset($p['endTime']) && is_numeric($p['endTime'])
                        ? now()->setTimestamp((int) ($p['endTime'] / 1000))->format('d/m/Y H:i')
                        : '-',
                ], $pacotes));
            }

            $palavra = (string) $this->option('palavra');
            $regiao = $this->option('regiao') ?: config('services.kalodata.regiao', 'BR');
            $doCache = Cache::has(KalodataService::chaveCache('/tiktok/category/searchByKeyword', [
                'region' => strtoupper($regiao),
                'keyword' => trim($palavra),
            ]));

            $categorias = $kalodata->buscarCategorias($palavra, $regiao);
            $this->info(sprintf('OK searchByKeyword "%s" (%s)%s: %d categoria(s).', $palavra, strtoupper($regiao), $doCache ? ' [cache]' : '', count($categorias)));
            foreach (array_slice($categorias, 0, 5) as $c) {
                $this->line(sprintf('  %s  %s', $c['id'] ?? '?', $c['name'] ?? '?'));
            }
        } catch (KalodataException $e) {
            $this->error("ERRO ({$e->tipo}): {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line("Chamadas reais hoje: {$kalodata->chamadasHoje()} de {$kalodata->tetoDiario()}.");

        return self::SUCCESS;
    }
}
