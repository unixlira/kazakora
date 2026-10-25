<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Caixas de referência dentro do limite da própria categoria (2026-10-25,
 * preenchimento da base de frete): a Grande era 80 x 50 x 40 cm, que pesa
 * 26,7 kg no cúbico (C x L x A / 6000) e saía mais cara que a Extra Grande
 * (média de R$ 288 contra R$ 177 no PAC do homolog); a Média era
 * 60 x 30 x 20 cm, 6 kg no cúbico para uma categoria de até 5 kg.
 *
 * Agora cada caixa é o pior caso que cabe na categoria: Média 60 x 25 x 20
 * (5 kg cúbico) e Grande 80 x 30 x 30 (12 kg cúbico). Só troca se a caixa
 * ainda for a original; a versão sobe e a cotação dessas duas recomeça.
 */
return new class extends Migration
{
    private const CAIXAS = [
        'media' => [[60, 30, 20], [60, 25, 20]],
        'grande' => [[80, 50, 40], [80, 30, 30]],
    ];

    public function up(): void
    {
        $this->trocar(0, 1);
    }

    public function down(): void
    {
        $this->trocar(1, 0);
    }

    private function trocar(int $de, int $para): void
    {
        foreach (self::CAIXAS as $chave => $caixas) {
            [$c, $l, $a] = $caixas[$de];
            [$nc, $nl, $na] = $caixas[$para];
            DB::table('frete_categorias_pacote')
                ->where('chave', $chave)
                ->where('ref_comprimento_cm', $c)->where('ref_largura_cm', $l)->where('ref_altura_cm', $a)
                ->update([
                    'ref_comprimento_cm' => $nc, 'ref_largura_cm' => $nl, 'ref_altura_cm' => $na,
                    'versao' => DB::raw('versao + 1'), 'updated_at' => now(),
                ]);
        }
    }
};
