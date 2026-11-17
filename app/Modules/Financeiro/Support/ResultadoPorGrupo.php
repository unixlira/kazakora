<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;

/**
 * Resultado de Plataformas x E-commerces num período, mês a mês (pedido do
 * Lira em 16/11/2026). Cada mês é um ContributionMargin::porCanal() do
 * pedaço do mês dentro do período; os grupos vêm de GruposDeCanais. O total
 * do período é a soma dos meses, então a tela fecha com a tabela, e a soma
 * dos grupos é a mesma conta de periodo().
 */
class ResultadoPorGrupo
{
    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public function __construct(private readonly ContributionMargin $margem)
    {
    }

    /**
     * Um período sem abrir por mês: [desde, ate).
     *
     * @return array<string, array>
     */
    public function grupos(Carbon $desde, Carbon $ate): array
    {
        return GruposDeCanais::agrupar($this->margem->porCanal($desde, $ate));
    }

    /**
     * Período [de, ate] com as duas datas incluídas, aberto por mês.
     *
     * @return array{grupos: array<string, array>, total: array}
     */
    public function porMes(Carbon $de, Carbon $ate): array
    {
        $fim = $ate->copy()->startOfDay()->addDay();
        $meses = [];
        for ($inicio = $de->copy()->startOfDay(); $inicio->lt($fim); $inicio = $inicio->copy()->startOfMonth()->addMonth()) {
            $corte = $inicio->copy()->startOfMonth()->addMonth();
            if ($corte->gt($fim)) {
                $corte = $fim->copy();
            }
            $meses[] = [
                'mes' => $inicio->format('Y-m'),
                'rotulo' => self::MESES[$inicio->month - 1].'/'.$inicio->year,
                'de' => $inicio->toDateString(),
                'ate' => $corte->copy()->subDay()->toDateString(),
                'grupos' => $this->grupos($inicio, $corte),
            ];
        }

        $grupos = [];
        foreach ([...array_keys(GruposDeCanais::GRUPOS), GruposDeCanais::OUTROS] as $grupo) {
            $doMes = array_values(array_filter(array_map(fn ($m) => $m['grupos'][$grupo] ?? null, $meses)));
            if ($doMes === [] && $grupo === GruposDeCanais::OUTROS) {
                continue;
            }

            $canais = [];
            foreach ($doMes as $g) {
                foreach (array_keys($g['canais']) as $canal) {
                    $canais[$canal] = true;
                }
            }

            $porCanal = [];
            foreach (array_keys($canais) as $canal) {
                $porCanal[] = [
                    'canal' => $canal,
                    'nome' => GruposDeCanais::nomeDoCanal($canal),
                    'sem_ads_integrado' => in_array($canal, GruposDeCanais::SEM_ADS_INTEGRADO, true),
                    'total' => GruposDeCanais::metricas(GruposDeCanais::somar(array_map(fn ($g) => $g['canais'][$canal] ?? [], $doMes))),
                ];
            }

            $grupos[$grupo] = [
                'chave' => $grupo,
                'nome' => GruposDeCanais::NOMES[$grupo],
                'descricao' => GruposDeCanais::DESCRICOES[$grupo],
                'total' => GruposDeCanais::metricas(GruposDeCanais::somar(array_map(fn ($g) => $g['total'], $doMes))),
                'canais' => $porCanal,
                'meses' => array_values(array_filter(array_map(fn ($m) => isset($m['grupos'][$grupo]) ? [
                    'mes' => $m['mes'],
                    'rotulo' => $m['rotulo'],
                    'de' => $m['de'],
                    'ate' => $m['ate'],
                    'total' => $m['grupos'][$grupo]['total'],
                    'canais' => array_map(fn ($canal) => ['canal' => $canal, 'nome' => GruposDeCanais::nomeDoCanal($canal)] + $m['grupos'][$grupo]['canais'][$canal],
                        array_keys($m['grupos'][$grupo]['canais'])),
                ] : null, $meses))),
            ];
        }

        return [
            'grupos' => $grupos,
            'total' => GruposDeCanais::metricas(GruposDeCanais::somar(array_map(fn ($g) => $g['total'], array_values($grupos)))),
        ];
    }
}
