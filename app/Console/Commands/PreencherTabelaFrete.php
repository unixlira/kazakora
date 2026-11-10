<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Jobs\PreencherTabelaFreteJob;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use App\Support\FreteProvedor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tabela de frete por localidade (pedido do Lira em 2026-10-25): importa as
 * localidades dos Correios e cota PAC/SEDEX das 4 categorias para cada uma.
 * Por padrão só agenda o PreencherTabelaFreteJob (segundo plano); --agora
 * roda aqui mesmo, mostrando o andamento. Erro (fila fora, Correios
 * recusando) vira alerta no painel do admin e o comando sai com falha.
 *
 * --provedor=melhor_envio (pedido do Lira em 2026-11-03) preenche as linhas
 * do Melhor Envio com os Correios conectados: o site continua nos Correios
 * e a tabela do Melhor Envio fica pronta para quando ele for conectado.
 *
 * --recusados (pedido do Lira em 2026-11-09): os 319 CEPs que o Melhor
 * Envio recusou na 1ª tabela (BH, Santo Ângelo...) cotam de novo, agora
 * tentando a sede do município e CEPs de rua do ViaCEP.
 */
class PreencherTabelaFrete extends Command
{
    protected $signature = 'frete:tabela-localidades
        {--uf=* : Só estas UFs (ex.: --uf=ES --uf=SP)}
        {--sem-importar : Não baixa as localidades de novo, só cota o que está pendente}
        {--agora : Roda neste processo em vez da fila}
        {--limite=0 : Com --agora, para depois de N CEPs cotados}
        {--provedor= : correios ou melhor_envio (padrão: o conectado em Logística)}
        {--recusados : Cota de novo só os CEPs que o Melhor Envio recusou (ficaram com o preço dos Correios)}';

    protected $description = 'Preenche a tabela de frete por localidade (PAC e SEDEX por categoria de tamanho)';

    public function handle(TabelaFreteLocalidades $tabela): int
    {
        $ufs = $this->option('sem-importar') ? [] : ($this->option('uf') ?: null);
        $provedor = $this->option('provedor') ?: null;
        if ($provedor !== null && ! in_array($provedor, FreteProvedor::TODOS, true)) {
            $this->error('Provedor inválido: use '.implode(' ou ', FreteProvedor::TODOS).'.');

            return self::INVALID;
        }

        if ($this->option('recusados')) {
            return $this->recotarRecusados($tabela);
        }

        try {
            return $this->executar($tabela, $ufs, $provedor);
        } catch (Throwable $e) {
            PreencherTabelaFreteJob::alertar('O comando frete:tabela-localidades falhou: '.$e->getMessage(), $provedor);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function recotarRecusados(TabelaFreteLocalidades $tabela): int
    {
        $ceps = $tabela->cepsRecusadosMelhorEnvio();
        $this->line(count($ceps).' CEPs recusados pelo Melhor Envio.');

        foreach (array_chunk($ceps, 10) as $lote) {
            try {
                $tabela->cotar($lote, FreteProvedor::MELHOR_ENVIO);
            } catch (Throwable $e) {
                $this->warn($e->getMessage());
            }
        }

        $restantes = count($tabela->cepsRecusadosMelhorEnvio());
        $this->info('Pronto: '.(count($ceps) - $restantes)." resolvidos, {$restantes} seguem com o preço dos Correios.");

        return self::SUCCESS;
    }

    /** @param  list<string>|null  $ufs */
    private function executar(TabelaFreteLocalidades $tabela, ?array $ufs, ?string $provedor): int
    {
        if (! $this->option('agora')) {
            PreencherTabelaFreteJob::iniciar($ufs, $provedor);
            $this->info('Tabela de frete agendada na fila (acompanhe em Logística → Frete por localidade).');

            return self::SUCCESS;
        }

        foreach ($ufs ?? TabelaFreteLocalidades::UFS as $uf) {
            $this->line("{$uf}: ".$tabela->importarUf($uf).' localidades');
        }

        $limite = (int) $this->option('limite');
        $total = 0;
        while ($ceps = $tabela->cepsPendentes(PreencherTabelaFreteJob::cepsPorVez($provedor), $provedor)) {
            $total += $tabela->cotar($ceps, $provedor);
            $this->line("Cotados: {$total}");
            if ($limite > 0 && $total >= $limite) {
                break;
            }
        }

        $this->info("Pronto: {$total} CEPs cotados.");

        return self::SUCCESS;
    }
}
