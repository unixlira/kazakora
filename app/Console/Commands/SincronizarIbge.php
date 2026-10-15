<?php

namespace App\Console\Commands;

use App\Models\IbgeMunicipio;
use App\Services\Ibge\IbgeLocalidadesSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atualiza as tabelas ibge_estados / ibge_municipios pela API (IBGE →
 * BrasilAPI). Se as duas falharem, não apaga nada; se a tabela estiver
 * vazia, carrega o arquivo versionado no repositório.
 */
class SincronizarIbge extends Command
{
    protected $signature = 'ibge:sincronizar {--arquivo : só carrega o arquivo versionado (database/data/ibge-localidades.json), sem API}';

    protected $description = 'Atualiza a tabela de estados e municípios do IBGE (usada na NF-e) sem apagar nada se a API falhar.';

    public function handle(IbgeLocalidadesSync $sync): int
    {
        if ($this->option('arquivo')) {
            $r = $sync->carregarArquivo();
            $this->info("Arquivo carregado: {$r['estados']} estados, {$r['municipios']} municípios.");

            return self::SUCCESS;
        }

        try {
            $r = $sync->sincronizarDaApi();
            $this->info("Sincronizado pela {$r['fonte']}: {$r['estados']} estados, {$r['municipios']} municípios.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->warn($e->getMessage());

            if (IbgeMunicipio::query()->doesntExist()) {
                $r = $sync->carregarArquivo();
                $this->info("Tabela vazia: carregado o arquivo do repositório ({$r['estados']} estados, {$r['municipios']} municípios).");

                return self::SUCCESS;
            }

            return self::FAILURE;
        }
    }
}
