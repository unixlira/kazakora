<?php

namespace App\Modules\Admin\Support;

/**
 * Termômetro de Oportunidades › KazaKora (Lira, 2026-11-18), com os pesos do
 * PLANO-KORABOX-CRIATIVOS-VENCEDORES (Etapa 2):
 *   dias no ar (≥ 60 = cheio) 30% · variações ativas (≥ 10) 25% ·
 *   alcance na UE (log) 20% · criativos novos em 7 dias (≥ 3) 15% ·
 *   anunciantes vendendo a mesma oferta (≥ 3) 10%.
 * Sem alcance (oferta fora da UE, a Biblioteca não mostra): os 20% são
 * redistribuídos entre os outros, para não punir quem só roda fora.
 *
 * A Biblioteca não mostra venda. "Escala" é o padrão de quem está ganhando:
 * muita variação no ar E criativo novo entrando toda semana.
 */
class TermometroOportunidade
{
    public const FASES = [
        'teste' => ['rotulo' => 'Em teste', 'descricao' => 'Poucos anúncios e pouco tempo no ar: ainda não provou que vende.'],
        'validando' => ['rotulo' => 'Validando', 'descricao' => 'Já roda há semanas com algumas variações: está se pagando.'],
        'escala' => ['rotulo' => 'Em escala', 'descricao' => 'Muitas variações no ar e criativo novo toda semana: vendendo e sendo escalada.'],
        'saturando' => ['rotulo' => 'Saturando', 'descricao' => 'Muito tempo no ar e nenhum criativo novo: o anunciante parou de investir.'],
    ];

    /** @return array{pontuacao: float, fase: string} */
    public static function medir(int $dias, int $variacoes, ?int $alcanceUe, int $novos7d, int $anunciantes): array
    {
        $partes = [
            'dias' => [min(1, $dias / 60), 30],
            'variacoes' => [min(1, $variacoes / 10), 25],
            'novos' => [min(1, $novos7d / 3), 15],
            'anunciantes' => [min(1, max(0, $anunciantes - 1) / 2), 10],
        ];
        if ($alcanceUe !== null) {
            // 1 mil pessoas = 0, 10 milhões = cheio.
            $partes['alcance'] = [max(0, min(1, (log10(max(1, $alcanceUe)) - 3) / 4)), 20];
        }

        $pesoTotal = array_sum(array_column($partes, 1));
        $pontos = array_sum(array_map(fn ($p) => $p[0] * $p[1], $partes));

        return [
            'pontuacao' => round($pontos / $pesoTotal * 100, 1),
            'fase' => self::fase($dias, $variacoes, $novos7d),
        ];
    }

    public static function fase(int $dias, int $variacoes, int $novos7d): string
    {
        return match (true) {
            $novos7d >= 3 && $variacoes >= 10 => 'escala',
            $dias >= 60 && $novos7d === 0 => 'saturando',
            $dias >= 14 && $variacoes >= 3 => 'validando',
            default => 'teste',
        };
    }
}
