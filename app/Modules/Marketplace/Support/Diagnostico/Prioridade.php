<?php

namespace App\Modules\Marketplace\Support\Diagnostico;

/**
 * Prioridade dos alertas (a mesma régua do Cauê e do Diego):
 *   P0 = perdendo dinheiro ou venda agora (prejuízo, ADS sem retorno, fora do ar)
 *   P1 = está freando as vendas (capa, conversão, meta de ROAS, preço, avaliação)
 *   P2 = melhoria do anúncio (fotos, vídeo, qualidade)
 *   OK = oportunidade para crescer
 */
class Prioridade
{
    private const ORDEM = ['P0' => 0, 'P1' => 1, 'P2' => 2, 'OK' => 3];

    public static function alerta(string $prioridade, string $tipo, string $texto): array
    {
        return ['prioridade' => $prioridade, 'tipo' => $tipo, 'texto' => $texto];
    }

    /** Mais urgente primeiro; no empate, o anúncio que mais fatura. */
    public static function ordenar(array $alertas): array
    {
        usort($alertas, fn ($a, $b) => [self::ORDEM[$a['prioridade']] ?? 9, -($a['produto']['receita_30d'] ?? 0)]
            <=> [self::ORDEM[$b['prioridade']] ?? 9, -($b['produto']['receita_30d'] ?? 0)]);

        return array_values($alertas);
    }
}
