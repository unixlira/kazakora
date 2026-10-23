<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\Publicidade\CentralDeCampanhas;
use App\Modules\Admin\Support\Publicidade\FonteDeCampanhas;
use App\Modules\Admin\Support\Publicidade\FonteIndisponivel;
use App\Modules\Admin\Support\Publicidade\PlataformaDeAnuncio;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central de campanhas (pedido do Lira em 2026-10-23): campanhas da Meta e
 * do Google numa lista só, com status, números do período e alertas.
 *
 * Só leitura: esta tela não pausa, não muda orçamento e não cria campanha
 * (dinheiro de ADS só com OK do Lira). O "Abrir no Gerenciador" é só link.
 * ROAS é o da plataforma; lucro é ContributionMargin. Nada vai para
 * channel_ad_spends.
 */
class PublicidadeCampanhasController extends Controller
{
    public function index(Request $request): Response
    {
        $periodo = CentralDeCampanhas::periodo($request->query('periodo'));
        $fontes = $this->fontes();

        return Inertia::render('Admin/Publicidade/Campanhas', [
            'periodo' => $periodo,
            'periodos' => CentralDeCampanhas::PERIODOS,
            'statusRotulos' => CentralDeCampanhas::STATUS,
            'plataformas' => array_map(fn (FonteDeCampanhas $f) => PlataformaDeAnuncio::dados($f->canal()) + [
                'conectada' => $f->conectada(),
            ], $fontes),
            // A Meta responde em alguns segundos: a tela abre antes e preenche depois.
            'central' => Inertia::defer(fn () => $this->central($fontes, $periodo)),
        ]);
    }

    /** @return list<FonteDeCampanhas> */
    private function fontes(): array
    {
        return array_values(array_map(fn (string $classe) => app($classe), CentralDeCampanhas::FONTES));
    }

    /**
     * @param  list<FonteDeCampanhas>  $fontes
     * @return array<string, mixed>
     */
    private function central(array $fontes, string $periodo): array
    {
        $campanhas = [];
        $alertas = [];
        $porPlataforma = [];
        $ocultas = 0;

        foreach ($fontes as $fonte) {
            $canal = $fonte->canal();
            $porPlataforma[$canal] = ['erro' => null, 'erro_alertas' => null, 'totais' => CentralDeCampanhas::totais([])];

            if (! $fonte->conectada()) {
                continue;
            }

            try {
                $linhas = $fonte->campanhas($periodo);
            } catch (FonteIndisponivel $erro) {
                $porPlataforma[$canal]['erro'] = $erro->getMessage();

                continue;
            }

            // Encerrada sem gasto no período só ocupa espaço: fica fora (e é contada).
            $visiveis = array_values(array_filter($linhas, fn ($l) => $l['status'] !== CentralDeCampanhas::ENCERRADA || $l['gasto'] > 0));
            $ocultas += count($linhas) - count($visiveis);
            $porPlataforma[$canal]['totais'] = CentralDeCampanhas::totais($visiveis);
            $campanhas = array_merge($campanhas, $visiveis);

            $daLista = CentralDeCampanhas::alertasDaLista($visiveis, $periodo);
            try {
                $daPlataforma = $fonte->alertas();
            } catch (FonteIndisponivel $erro) {
                $daPlataforma = [];
                $porPlataforma[$canal]['erro_alertas'] = $erro->getMessage();
            }
            foreach ([...$daLista, ...$daPlataforma] as $alerta) {
                $alertas[] = ['plataforma' => $canal] + $alerta;
            }
        }

        $ordem = ['urgente' => 0, 'atencao' => 1];
        usort($alertas, fn ($a, $b) => ($ordem[$a['prioridade']] ?? 2) <=> ($ordem[$b['prioridade']] ?? 2));

        return [
            'campanhas' => $campanhas,
            'porPlataforma' => $porPlataforma,
            'totais' => CentralDeCampanhas::totais($campanhas),
            'alertas' => $alertas,
            'ocultas' => $ocultas,
            'gerado_em' => now('America/Sao_Paulo')->toIso8601String(),
        ];
    }
}
