<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\FreteRegioes;
use App\Modules\Checkout\Support\LocalPorCep;
use Illuminate\Console\Command;

/**
 * Só leitura: mostra o município do CEP, a regra de frete por cidade/região
 * que vale para ele (pedido 2026-10-24) e o PAC/SEDEX antes e depois dela.
 */
class ConsultarFreteRegiao extends Command
{
    protected $signature = 'frete:regiao {cep} {--peso=1000 : peso tarifável em gramas}';

    protected $description = 'Mostra a regra de frete por cidade/região de um CEP e o frete antes e depois dela';

    public function handle(LocalPorCep $locais, FreteRegioes $regioes, FreteEmbutido $frete): int
    {
        $cep = preg_replace('/\D/', '', (string) $this->argument('cep'));
        $peso = max(1, (int) $this->option('peso'));

        $local = $locais->consultar($cep);
        $this->line('Local: '.($local ? "{$local['cidade']}/{$local['uf']} (IBGE {$local['municipio_id']})" : 'não encontrado'));

        foreach (['pac', 'sedex'] as $servico) {
            $regra = $regioes->regra($cep, $peso, $servico);
            $this->line(strtoupper($servico).': '.($regra
                ? "regra #{$regra['id']} \"{$regra['nome']}\" ({$regra['tipo_preco']} ".($regra['percentual'] ? "{$regra['valor']}%" : "R\$ {$regra['valor']}").", {$regra['local']})"
                : 'sem regra (cotação dos Correios)'));
        }

        $linha = null;
        foreach ($frete->tabela($cep) ?? [] as $limite => $l) {
            if ($peso <= $limite) {
                $linha = $l;
                break;
            }
        }

        if (! $linha || $linha['pac'] === null || $linha['sedex'] === null) {
            $this->warn('Sem cotação dos Correios para este CEP e peso.');

            return self::SUCCESS;
        }

        $final = $regioes->aplicar($linha, $cep, $peso);
        $this->table(['', 'Correios', 'Com a regra'], [
            ['PAC', $this->fmt($linha['pac'], $linha['prazo_pac']), $this->fmt($final['pac'], $final['prazo_pac'])],
            ['SEDEX', $this->fmt($linha['sedex'], $linha['prazo_sedex']), $this->fmt($final['sedex'], $final['prazo_sedex'])],
        ]);

        return self::SUCCESS;
    }

    private function fmt(float $preco, ?int $prazo): string
    {
        return 'R$ '.number_format($preco, 2, ',', '.').($prazo ? " em {$prazo} d" : '');
    }
}
