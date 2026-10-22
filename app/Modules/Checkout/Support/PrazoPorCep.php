<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Services\CorreiosFreightQuoteService;
use Illuminate\Support\Facades\Cache;

/**
 * Prazo pelo CEP de um produto: Full (entrega expressa na área da Grande SP)
 * ou Flex (Correios entregam em até 3 dias úteis). Usado pela página do
 * produto do KazaKora e pelo site FerraKora, com a mesma regra.
 */
class PrazoPorCep
{
    public const DIAS_FLEX = 3;

    public function __construct(
        private readonly EntregaExpressa $entregaExpressa,
        private readonly CorreiosFreightQuoteService $correios,
    ) {}

    /**
     * @return array{entrega_expressa: array<string, mixed>|null, prazo_dias: int|null, modalidade: string|null}
     */
    public function consultar(string $cep, ?Product $product): array
    {
        $expressa = $this->entregaExpressa->consultar($cep);

        // Fora da entrega expressa: prazo mais rápido dos Correios para esse CEP
        // e produto. Em cache por 1 dia (a consulta é lenta).
        $prazoDias = null;
        if (! $expressa && $product) {
            $prazoDias = Cache::remember("prazo-cep:{$cep}:{$product->id}", now()->addDay(), function () use ($product, $cep) {
                $dias = collect(rescue(fn () => $this->correios->quote(collect([['product' => $product, 'quantity' => 1, 'subtotal' => (float) $product->final_price]]), $cep), [], false))
                    ->pluck('estimated_days')->filter(fn ($dias) => $dias > 0);

                return $dias->isEmpty() ? 0 : (int) $dias->min();
            }) ?: null;
        }

        return [
            'entrega_expressa' => $expressa,
            'prazo_dias' => $prazoDias,
            'modalidade' => $expressa ? 'full' : ($prazoDias !== null && $prazoDias <= self::DIAS_FLEX ? 'flex' : null),
        ];
    }
}
