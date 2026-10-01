<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use Illuminate\Console\Command;

/**
 * BUG REAL 2026-10-01: 2 vendas da Shopee sem nota e sem etiqueta porque o
 * produto tinha cadastro fiscal sem CST de PIS/COFINS (criado só com
 * peso/medidas pelo PackageDataResolver ou pela tela de logística).
 *
 * Completa em TODO produto os campos fiscais que são iguais pra empresa
 * inteira (CFOP, CSOSN, PIS/COFINS, origem, unidade) quando estão vazios.
 * Nunca sobrescreve o que já está preenchido. NCM não se chuta: quem fica
 * sem NCM sai listado pra cadastro (ou `shopee:sync-fiscal-data`, que puxa
 * da Shopee; o Mercado Livre não expõe dado fiscal por anúncio na API).
 */
class CompleteProductFiscalDefaults extends Command
{
    protected $signature = 'fiscal:completar-padroes {--dry-run : Só mostra o que mudaria, sem gravar}';

    protected $description = 'Completa CST de PIS/COFINS, CSOSN, CFOP, origem e unidade vazios em todos os produtos.';

    public function handle(): int
    {
        $padroes = ProductFiscalData::defaultMeiAttributes();
        $dryRun = (bool) $this->option('dry-run');
        $alterados = 0;
        $semNcm = [];

        Product::query()->with('fiscalData')->orderBy('id')->each(function (Product $product) use ($padroes, $dryRun, &$alterados, &$semNcm) {
            $fiscal = $product->fiscalData;
            $faltando = [];

            foreach ($padroes as $campo => $valor) {
                $atual = $fiscal?->{$campo};

                if ($atual === null || trim((string) $atual) === '') {
                    $faltando[$campo] = $valor;
                } elseif (in_array($campo, ['pis_situacao_tributaria', 'cofins_situacao_tributaria'], true)
                    && ctype_digit((string) $atual) && strlen((string) $atual) === 1) {
                    // "8" sem o zero também gerava PIS vazio no XML.
                    $faltando[$campo] = str_pad((string) $atual, 2, '0', STR_PAD_LEFT);
                }
            }

            if (! $fiscal?->ncm) {
                $semNcm[] = "#{$product->id} {$product->sku} {$product->name}";
            }

            if ($faltando === []) {
                return;
            }

            $alterados++;
            $this->line(sprintf('#%d %s: %s', $product->id, $product->name, implode(', ', array_map(
                fn ($campo, $valor) => "{$campo}={$valor}",
                array_keys($faltando),
                $faltando,
            ))));

            if (! $dryRun) {
                $product->fiscalData()->updateOrCreate(['product_id' => $product->id], $faltando);
            }
        });

        $this->info(sprintf('%d produto(s) %s.', $alterados, $dryRun ? 'seriam completados (dry-run, nada gravado)' : 'completados'));

        if ($semNcm !== []) {
            $this->warn(sprintf('%d produto(s) SEM NCM (nota não sai até cadastrar):', count($semNcm)));
            foreach ($semNcm as $linha) {
                $this->line("  - {$linha}");
            }
        }

        return self::SUCCESS;
    }
}
