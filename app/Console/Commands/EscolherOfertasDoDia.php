<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CalculadoraOferta;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ofertas do dia (pedido 2026-10-10): escolhe 5 produtos com mais de 25% de
 * desconto e dá +5 pontos de desconto, só se a conta fechar sem prejuízo
 * (CalculadoraOferta). Não repete produto dos últimos dias; sem candidatos
 * suficientes, completa com os de melhor margem que aguentam o extra.
 * Roda todo dia 00:05 e a home chama se o dia ainda estiver sem ofertas.
 */
class EscolherOfertasDoDia extends Command
{
    protected $signature = 'loja:ofertas-do-dia {--forcar : Refaz as ofertas de hoje}';

    protected $description = 'Escolhe os produtos da Oferta do Dia (sem prejuízo)';

    public function handle(): int
    {
        $hoje = now()->toDateString();

        if (OfertaDoDia::query()->whereDate('data', $hoje)->exists() && ! $this->option('forcar')) {
            $this->info('Ofertas de hoje já escolhidas.');

            return self::SUCCESS;
        }

        $quantidade = (int) config('ofertas.quantidade');
        $extra = (float) config('ofertas.desconto_extra');
        $recentes = OfertaDoDia::query()
            ->where('data', '>=', now()->subDays((int) config('ofertas.dias_sem_repetir'))->toDateString())
            ->whereDate('data', '<', $hoje)
            ->pluck('product_id')
            ->all();

        $candidatos = Product::query()
            ->where('is_active', true)
            ->whereNull('parent_product_id')
            ->where('stock', '>', 0)
            ->where('cost_price', '>', 0)
            ->where('price', '>', 0)
            ->get()
            ->map(function (Product $product) use ($extra) {
                $preco = CalculadoraOferta::precoOferta($product, $extra);

                return [
                    'product' => $product,
                    'desconto' => CalculadoraOferta::descontoAtual($product),
                    'preco' => $preco,
                    'lucro' => CalculadoraOferta::lucroPiorCaso($product, $preco),
                    'seguro' => CalculadoraOferta::seguro($product, $preco),
                ];
            })
            ->filter(fn ($item) => $item['seguro']);

        $escolhidos = $this->escolher($candidatos->where('desconto', '>', (float) config('ofertas.desconto_minimo')), $recentes, $quantidade);

        if ($escolhidos->count() < $quantidade && config('ofertas.completar_com_margem')) {
            $faltam = $candidatos->reject(fn ($item) => $escolhidos->contains(fn ($ja) => $ja['product']->id === $item['product']->id))
                ->sortByDesc(fn ($item) => $item['lucro'] / max($item['preco'], 0.01));
            $escolhidos = $escolhidos->concat($this->escolher($faltam, $recentes, $quantidade - $escolhidos->count(), false));
        }

        DB::transaction(function () use ($hoje, $escolhidos, $extra) {
            OfertaDoDia::query()->whereDate('data', $hoje)->delete();
            $escolhidos->values()->each(fn ($item, $posicao) => OfertaDoDia::query()->create([
                'data' => $hoje,
                'product_id' => $item['product']->id,
                'posicao' => $posicao,
                'desconto_extra' => $extra,
                'preco_antes' => $item['product']->precoSemOferta(),
                'preco_oferta' => $item['preco'],
                'lucro_estimado' => $item['lucro'],
            ]));
        });
        OfertaDoDia::esquecer();

        $this->info("Ofertas de {$hoje}: ".$escolhidos->count().' produto(s).');
        foreach ($escolhidos as $item) {
            $this->line("- #{$item['product']->id} {$item['product']->name}: R$ {$item['preco']} (lucro pior caso R$ {$item['lucro']})");
        }

        return self::SUCCESS;
    }

    /**
     * Prefere quem não esteve em oferta nos últimos dias; se não der, aceita
     * repetir. Aleatório entre os elegíveis pra variar a vitrine.
     */
    private function escolher(Collection $candidatos, array $recentes, int $quantidade, bool $embaralhar = true): Collection
    {
        if ($quantidade <= 0) {
            return collect();
        }

        $novos = $candidatos->reject(fn ($item) => in_array($item['product']->id, $recentes, true));
        $repetidos = $candidatos->filter(fn ($item) => in_array($item['product']->id, $recentes, true));

        $ordenar = fn (Collection $lista) => $embaralhar ? $lista->shuffle() : $lista;

        return $ordenar($novos)->concat($ordenar($repetidos))->take($quantidade)->values();
    }
}
