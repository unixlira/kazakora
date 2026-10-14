<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Relatório de produtos duplicados na loja (pedido 2026-10-13, caso das
 * bicicletas spinning #82 e #206). SÓ LÊ: unificar ou desativar é decisão
 * do Lira, porque "parecido" nem sempre é o mesmo produto (lixeira 3L x 5L,
 * kit 46 x 121 peças).
 *
 * Suspeita = produtos ativos (sem pai) com nome parecido (palavras em comum)
 * ou com o mesmo custo e nome meio parecido, mais os anúncios copiados de
 * outro produto (attributes.source_product_id). Mostra o que difere entre
 * eles (preço, custo, estoque, números do nome) para separar duplicado de
 * variação (tamanho, voltagem, quantidade).
 */
class CatalogoDuplicados extends Command
{
    protected $signature = 'catalogo:duplicados {--min=0.5 : Semelhança mínima do nome (0 a 1)}';

    protected $description = 'Lista produtos ativos que parecem duplicados (só leitura).';

    private const PALAVRAS_VAZIAS = ['de', 'da', 'do', 'das', 'dos', 'para', 'com', 'e', 'em', 'o', 'a', 'kit', 'pc', 'pcs', 'profissional'];

    public function handle(): int
    {
        $min = (float) $this->option('min');
        $produtos = Product::query()->where('is_active', true)->whereNull('parent_product_id')
            ->with('channelListings')
            ->get(['id', 'sku', 'name', 'price', 'cost_price', 'stock'])
            ->values();
        $palavras = $produtos->mapWithKeys(fn (Product $p) => [$p->id => $this->palavras($p->name)]);

        $pares = collect();
        for ($i = 0; $i < $produtos->count(); $i++) {
            for ($j = $i + 1; $j < $produtos->count(); $j++) {
                [$a, $b] = [$produtos[$i], $produtos[$j]];
                $sim = $this->semelhanca($palavras[$a->id], $palavras[$b->id]);
                $mesmoCusto = $a->cost_price !== null && (float) $a->cost_price > 0 && (float) $a->cost_price === (float) $b->cost_price;
                if ($sim >= $min || ($mesmoCusto && $sim >= 0.35)) {
                    $pares->push(compact('a', 'b', 'sim', 'mesmoCusto'));
                }
            }
        }

        $copias = $produtos->flatMap(fn (Product $p) => $p->channelListings
            ->filter(fn ($l) => isset($l->attributes['source_product_id']) && (int) $l->attributes['source_product_id'] !== $p->id)
            ->map(fn ($l) => "#{$p->id} tem anúncio {$l->channel} copiado do #{$l->attributes['source_product_id']}"));

        $this->table(['Sem.', 'Produto A', 'Produto B', 'Diferenças'], $pares->sortByDesc('sim')->map(fn ($par) => [
            number_format($par['sim'], 2).($par['mesmoCusto'] ? ' $' : ''),
            "#{$par['a']->id} ".Str::limit($par['a']->name, 50),
            "#{$par['b']->id} ".Str::limit($par['b']->name, 50),
            $this->diferencas($par['a'], $par['b']),
        ])->all());
        $copias->each(fn ($linha) => $this->line($linha));
        $this->info("{$pares->count()} pares suspeitos, {$copias->count()} anúncios copiados. Nada foi alterado.");

        return self::SUCCESS;
    }

    private function palavras(string $nome): array
    {
        $palavras = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($nome)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($palavras, fn ($w) => strlen($w) > 1 && ! in_array($w, self::PALAVRAS_VAZIAS, true))));
    }

    private function semelhanca(array $a, array $b): float
    {
        $uniao = count(array_unique([...$a, ...$b]));

        return $uniao ? count(array_intersect($a, $b)) / $uniao : 0;
    }

    private function diferencas(Product $a, Product $b): string
    {
        $numeros = fn (string $nome): Collection => collect(preg_match_all('/\d+[.,]?\d*/', $nome, $m) ? $m[0] : []);
        $diff = [];
        if ($numeros($a->name)->diff($numeros($b->name))->isNotEmpty() || $numeros($b->name)->diff($numeros($a->name))->isNotEmpty()) {
            $diff[] = 'números do nome: '.$numeros($a->name)->implode('/').' x '.$numeros($b->name)->implode('/');
        }
        foreach (['price' => 'preço', 'cost_price' => 'custo', 'stock' => 'estoque'] as $campo => $rotulo) {
            if ((string) $a->{$campo} !== (string) $b->{$campo}) {
                $diff[] = "{$rotulo} {$a->{$campo}} x {$b->{$campo}}";
            }
        }

        return implode('; ', $diff) ?: 'nenhuma nos campos básicos';
    }
}
