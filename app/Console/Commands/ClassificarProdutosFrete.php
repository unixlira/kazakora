<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\FreteCategoriaPacote;
use App\Modules\Checkout\Support\FreteEmbutido;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Classifica os produtos nas categorias de tamanho da tabela de frete por
 * localidade (2026-10-25, preenchimento da base de frete): sem categoria o
 * site não usa a tabela. A categoria é a mesma sugestão da tela Frete por
 * localidade (peso tarifável e maior lado).
 *
 * Fica de fora, com o motivo, o produto que não cabe na caixa de referência
 * da categoria (peso tarifável ou maior lado acima dela): a tabela cobraria
 * menos que o frete real, então ele continua na cotação pelo peso. Produto
 * sem peso no cadastro fiscal também fica de fora.
 *
 * Sem --aplicar só mostra o que faria. Variações seguem o pai.
 */
class ClassificarProdutosFrete extends Command
{
    protected $signature = 'frete:classificar-produtos
        {--aplicar : Grava a categoria nos produtos}
        {--refazer : Reclassifica também os que já têm categoria}';

    protected $description = 'Classifica os produtos nas categorias de tamanho da tabela de frete (Pequena, Média, Grande, Extra Grande)';

    public function handle(FreteEmbutido $frete): int
    {
        $caixas = DB::table('frete_categorias_pacote')->whereNotNull('ref_peso_g')->get()->keyBy('chave')
            ->map(fn ($c) => [
                'peso' => self::pesoTarifavel((int) $c->ref_peso_g, (float) $c->ref_comprimento_cm, (float) $c->ref_largura_cm, (float) $c->ref_altura_cm),
                'lado' => max((float) $c->ref_comprimento_cm, (float) $c->ref_largura_cm, (float) $c->ref_altura_cm),
            ]);

        $produtos = Product::query()
            ->whereNull('parent_product_id')
            ->when(! $this->option('refazer'), fn ($q) => $q->whereNull('categoria_frete'))
            ->with('fiscalData:id,product_id,peso_bruto')
            ->orderBy('id')
            ->get(['id', 'name', 'sku', 'is_active', 'categoria_frete']);

        $porCategoria = [];
        $foraLinhas = [];
        $gravar = [];
        foreach ($produtos as $p) {
            if (! $p->fiscalData?->peso_bruto) {
                $foraLinhas[] = [$p->id, $p->sku, mb_strimwidth($p->name, 0, 50, '…'), '-', 'sem peso no cadastro fiscal'];

                continue;
            }

            $peso = $frete->pesoTarifavel($p);
            $lado = $frete->maiorLado($p);
            $categoria = FreteCategoriaPacote::classificar($peso, $lado);
            $caixa = $caixas[$categoria] ?? null;

            $motivo = match (true) {
                $caixa === null => 'categoria sem caixa de referência',
                $peso > $caixa['peso'] => sprintf('%s kg tarifável, caixa da categoria %s kg', self::kg($peso), self::kg($caixa['peso'])),
                $lado > $caixa['lado'] => sprintf('lado de %s cm, caixa da categoria %s cm', $lado, $caixa['lado']),
                default => null,
            };
            if ($motivo) {
                $foraLinhas[] = [$p->id, $p->sku, mb_strimwidth($p->name, 0, 50, '…'), $categoria, $motivo];

                continue;
            }

            $porCategoria[$categoria] = ($porCategoria[$categoria] ?? 0) + 1;
            if ($p->categoria_frete !== $categoria) {
                $gravar[$categoria][] = $p->id;
            }
        }

        $this->table(['Categoria', 'Produtos'], collect($porCategoria)->map(fn ($n, $c) => [$c, $n])->values()->all());
        if ($foraLinhas !== []) {
            $this->warn(count($foraLinhas).' ficam na cotação pelo peso:');
            $this->table(['ID', 'SKU', 'Produto', 'Categoria', 'Motivo'], $foraLinhas);
        }

        if (! $this->option('aplicar')) {
            $this->info('Simulação: nada gravado (use --aplicar).');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($gravar as $categoria => $ids) {
            $total += Product::query()->whereIn('id', $ids)->update(['categoria_frete' => $categoria]);
        }
        FreteEmbutido::esquecerCategorias();
        $this->info("Gravado: {$total} produtos.");

        return self::SUCCESS;
    }

    /** Mesma conta do FreteEmbutido: o cúbico (C x L x A / 6000 kg) vale quando passa de 5 kg. */
    private static function pesoTarifavel(int $realG, float $c, float $l, float $a): int
    {
        $cubico = (int) round($c * $l * $a / 6);

        return $cubico > 5000 ? max($realG, $cubico) : $realG;
    }

    private static function kg(int $g): string
    {
        return number_format($g / 1000, 1, ',', '');
    }
}
