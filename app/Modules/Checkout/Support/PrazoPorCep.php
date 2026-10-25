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

    /**
     * Linhas da tabela de entrega da página do produto (pedido 2026-10-23),
     * na ordem: Full (ou Flex), Turbo e, por último, Padrão (Correios ou
     * transportadora, quando passa de 3 dias úteis). O Turbo (SEDEX pela
     * diferença do que o preço já paga) aparece também no raio do Full.
     * Flex e Padrão: "em até X dias úteis (mas geralmente chega em Y)", com Y
     * = prazo dos Correios do serviço que o preço já paga (o mesmo do
     * checkout) e X = Y + folga de postagem. Sem prazo, X = prazo_padrao_dias.
     *
     * @param  array{entrega_expressa: ?array, prazo_dias: ?int}  $prazo  resultado de consultar()
     * @param  array{prazo: ?int, turbo: ?array}|null  $entrega  FreteEmbutido::entregaDoCarrinho()
     * @return list<array<string, mixed>>
     */
    public static function opcoes(array $prazo, ?array $entrega): array
    {
        $turbo = $entrega['turbo'] ?? null;

        if ($prazo['entrega_expressa']) {
            $opcoes = [[
                'tipo' => 'full',
                'quando' => $prazo['entrega_expressa']['tipo'],
                // "Receba SEGUNDA" no fim de semana (pedido do Lira 2026-10-24).
                'mensagem' => $prazo['entrega_expressa']['mensagem'] ?? null,
                'horario' => (string) config('entrega_expressa.horario_entrega', '21h'),
                'preco' => 0.0,
            ]];
            // No Full o "dias antes" compara com o PAC, que aqui não aparece.
            if ($turbo) {
                $opcoes[] = ['tipo' => 'turbo', 'ate' => $turbo['prazo'], 'dias_a_menos' => null, 'preco' => (float) $turbo['preco']];
            }

            return $opcoes;
        }

        $geralmente = ($entrega['prazo'] ?? null) ?: $prazo['prazo_dias'];
        $gratis = [
            'tipo' => $geralmente !== null && $geralmente <= self::DIAS_FLEX ? 'flex' : 'padrao',
            'ate' => $geralmente !== null
                ? $geralmente + (int) config('frete_embutido.folga_dias', 2)
                : (int) config('frete_embutido.prazo_padrao_dias', 7),
            'geralmente' => $geralmente,
            'preco' => 0.0,
        ];
        $opcoes = $gratis['tipo'] === 'flex' ? [$gratis] : [];

        if ($turbo) {
            $opcoes[] = ['tipo' => 'turbo', 'ate' => $turbo['prazo'], 'dias_a_menos' => $turbo['dias_a_menos'], 'preco' => (float) $turbo['preco']];
        }

        // Correios ou transportadora sempre por último.
        if ($gratis['tipo'] === 'padrao') {
            $opcoes[] = $gratis;
        }

        return $opcoes;
    }
}
