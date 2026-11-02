<?php

namespace App\Console\Commands;

use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Catalog\Models\Product;
use Illuminate\Console\Command;

/**
 * Página do produto (Botrel, 2026-11-02), por visitante: até onde rolou, quem
 * usou a calculadora de CEP e quem clicou em COMPRAR AGORA, separado por aparelho.
 */
class FunilDoProduto extends Command
{
    protected $signature = 'produto:funil {--dias=7 : Quantos dias para trás}';

    protected $description = 'Rolagem, uso do CEP e clique em comprar na página do produto';

    public function handle(): int
    {
        $eventos = CheckoutEvento::query()
            ->whereIn('evento', [CheckoutEvento::PRODUTO_ROLAGEM, CheckoutEvento::PRODUTO_CEP, CheckoutEvento::PRODUTO_COMPRAR])
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->get(['visitor_id', 'evento', 'campo', 'valor', 'dispositivo']);

        $nomes = Product::query()->whereIn('id', $eventos->pluck('campo')->unique())->pluck('name', 'id');

        $linhas = $eventos->groupBy(fn ($e) => $e->campo.'|'.$e->dispositivo)->map(function ($doProduto, $chave) use ($nomes) {
            [$produto, $aparelho] = explode('|', $chave);
            $visitantes = fn (string $evento, ?int $marco = null) => $doProduto->where('evento', $evento)
                ->when($marco, fn ($lista) => $lista->where('valor', $marco))
                ->pluck('visitor_id')->unique()->count();

            return [
                mb_strimwidth((string) ($nomes[$produto] ?? $produto), 0, 40, '...'), $aparelho,
                $visitantes(CheckoutEvento::PRODUTO_ROLAGEM, 25), $visitantes(CheckoutEvento::PRODUTO_ROLAGEM, 50),
                $visitantes(CheckoutEvento::PRODUTO_ROLAGEM, 75), $visitantes(CheckoutEvento::PRODUTO_ROLAGEM, 100),
                $visitantes(CheckoutEvento::PRODUTO_CEP), $visitantes(CheckoutEvento::PRODUTO_COMPRAR),
            ];
        })->sortByDesc(7)->values()->all();

        $this->table(['Produto', 'Aparelho', '25%', '50%', '75%', '100%', 'Usou CEP', 'Comprar'], $linhas);

        return self::SUCCESS;
    }
}
