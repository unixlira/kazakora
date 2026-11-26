<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CartoesDeOpcoes;
use Illuminate\Console\Command;

/**
 * Liga (ou desliga) as opções de um anúncio em cartões no formato dos kits do Livro Falante
 * (pedido do Lira 26/11/2026), a partir de database/cartoes-opcoes/<nome>.json, que fica no git:
 *
 *   produto  id do pai (o anúncio com as variações)
 *   slug     slug do pai, para conferir que é o produto certo
 *   cartoes  o JSON gravado em products.cartoes_opcoes (formato em CartoesDeOpcoes)
 *
 *   php artisan loja:cartoes-opcoes compressor-4-em-1             mostra o que faria
 *   php artisan loja:cartoes-opcoes compressor-4-em-1 --aplicar   grava
 *   php artisan loja:cartoes-opcoes compressor-4-em-1 --desligar  volta ao seletor de sempre
 */
class CartoesDeOpcoesAplicar extends Command
{
    protected $signature = 'loja:cartoes-opcoes {nome : Arquivo em database/cartoes-opcoes, sem .json} {--aplicar : Grava (sem ele, só mostra).} {--desligar : Apaga os cartões do produto.}';

    protected $description = 'Liga ou desliga as opções do anúncio em cartões (database/cartoes-opcoes).';

    public function handle(): int
    {
        $nome = (string) $this->argument('nome');
        $arquivo = database_path("cartoes-opcoes/{$nome}.json");
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        if (! is_array($dados) || ! is_array($dados['cartoes'] ?? null)) {
            $this->error("Arquivo inválido ou ausente: {$arquivo}");

            return self::FAILURE;
        }

        $produto = Product::query()->find((int) ($dados['produto'] ?? 0));
        if ($produto === null || (filled($dados['slug'] ?? null) && $produto->slug !== $dados['slug'])) {
            $this->error('Produto não encontrado ou com outro slug.');

            return self::FAILURE;
        }
        if ($produto->parent_product_id) {
            $this->error('Grave no pai (o anúncio), não numa variação.');

            return self::FAILURE;
        }

        if ($this->option('desligar')) {
            $produto->forceFill(['cartoes_opcoes' => null])->save();
            $this->info("Cartões desligados no produto {$produto->id}.");

            return self::SUCCESS;
        }

        $problemas = CartoesDeOpcoes::problemas($dados['cartoes']);
        $ids = $produto->children()->pluck('id')->push($produto->id)->all();
        foreach ((array) $dados['cartoes']['itens'] as $item) {
            if (! in_array((int) ($item['id'] ?? 0), $ids, true)) {
                $problemas[] = 'opção '.($item['id'] ?? '?').' não é o produto nem uma variação dele';
            }
        }
        if ($problemas !== []) {
            foreach ($problemas as $problema) {
                $this->error($problema);
            }

            return self::FAILURE;
        }

        foreach ($dados['cartoes']['itens'] as $item) {
            $this->line(sprintf('%d  %s%s  (%d vantagens)', $item['id'], $item['nome'], ! empty($item['destaque']) ? '  [recomendado]' : '', count($item['vantagens'] ?? [])));
        }
        if (! $this->option('aplicar')) {
            $this->comment('Nada gravado (use --aplicar).');

            return self::SUCCESS;
        }

        $produto->forceFill(['cartoes_opcoes' => $dados['cartoes']])->save();
        $this->info("Cartões gravados no produto {$produto->id}.");

        return self::SUCCESS;
    }
}
