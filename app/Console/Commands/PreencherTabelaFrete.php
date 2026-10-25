<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Jobs\PreencherTabelaFreteJob;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tabela de frete por localidade (pedido do Lira em 2026-10-25): importa as
 * localidades dos Correios e cota PAC/SEDEX das 4 categorias para cada uma.
 * Por padrão só agenda o PreencherTabelaFreteJob (segundo plano); --agora
 * roda aqui mesmo, mostrando o andamento. Erro (fila fora, Correios
 * recusando) vira alerta no painel do admin e o comando sai com falha.
 */
class PreencherTabelaFrete extends Command
{
    protected $signature = 'frete:tabela-localidades
        {--uf=* : Só estas UFs (ex.: --uf=ES --uf=SP)}
        {--sem-importar : Não baixa as localidades de novo, só cota o que está pendente}
        {--agora : Roda neste processo em vez da fila}
        {--limite=0 : Com --agora, para depois de N CEPs cotados}';

    protected $description = 'Preenche a tabela de frete por localidade (PAC e SEDEX por categoria de tamanho)';

    public function handle(TabelaFreteLocalidades $tabela): int
    {
        $ufs = $this->option('sem-importar') ? [] : ($this->option('uf') ?: null);

        try {
            return $this->executar($tabela, $ufs);
        } catch (Throwable $e) {
            PreencherTabelaFreteJob::alertar('O comando frete:tabela-localidades falhou: '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** @param  list<string>|null  $ufs */
    private function executar(TabelaFreteLocalidades $tabela, ?array $ufs): int
    {
        if (! $this->option('agora')) {
            PreencherTabelaFreteJob::iniciar($ufs);
            $this->info('Tabela de frete agendada na fila (acompanhe em Logística → Frete por localidade).');

            return self::SUCCESS;
        }

        foreach ($ufs ?? TabelaFreteLocalidades::UFS as $uf) {
            $this->line("{$uf}: ".$tabela->importarUf($uf).' localidades');
        }

        $limite = (int) $this->option('limite');
        $total = 0;
        while ($ceps = $tabela->cepsPendentes(PreencherTabelaFreteJob::CEPS_POR_VEZ)) {
            $total += $tabela->cotar($ceps);
            $this->line("Cotados: {$total}");
            if ($limite > 0 && $total >= $limite) {
                break;
            }
        }

        $this->info("Pronto: {$total} CEPs cotados.");

        return self::SUCCESS;
    }
}
