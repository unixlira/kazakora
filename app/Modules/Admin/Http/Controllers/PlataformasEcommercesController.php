<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financeiro\Support\GruposDeCanais;
use App\Modules\Financeiro\Support\ResultadoPorGrupo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Financeiro › Plataformas e E-commerces (pedido do Lira em 16/11/2026):
 * receita, ADS, ADS %, lucro, margem e pedidos separados em marketplaces
 * (Plataformas) e sites próprios (E-commerces), por canal e por mês.
 *
 * Lucro é o de ContributionMargin::porCanal(); os grupos estão em
 * GruposDeCanais (a mesma lista do e-mail diário). Só leitura.
 */
class PlataformasEcommercesController extends Controller
{
    /** Teto do filtro, para a tela não varrer anos de pedido (13 meses). */
    private const DIAS_MAXIMO = 400;

    public function index(Request $request, ResultadoPorGrupo $resultado): Response
    {
        [$de, $ate] = $this->periodo($request);
        $aba = in_array($request->query('aba'), array_keys(GruposDeCanais::GRUPOS), true)
            ? $request->query('aba')
            : GruposDeCanais::PLATAFORMAS;

        return Inertia::render('Admin/Financeiro/PlataformasEcommerces', [
            'filtros' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString(), 'aba' => $aba],
            'resultado' => $resultado->porMes($de, $ate),
            'semAdsIntegrado' => array_map(fn ($canal) => GruposDeCanais::nomeDoCanal($canal), GruposDeCanais::SEM_ADS_INTEGRADO),
        ]);
    }

    /**
     * Datas do filtro (?de=AAAA-MM-DD&ate=AAAA-MM-DD, as duas incluídas).
     * Padrão: este mês até hoje. Data inválida volta para o padrão; data
     * invertida troca.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodo(Request $request): array
    {
        $ler = function (mixed $valor) {
            if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
                return null;
            }
            [$ano, $mes, $dia] = array_map('intval', explode('-', $valor));

            return checkdate($mes, $dia, $ano) ? Carbon::create($ano, $mes, $dia)->startOfDay() : null;
        };

        $ate = $ler($request->query('ate')) ?? Carbon::today();
        $de = $ler($request->query('de')) ?? $ate->copy()->startOfMonth();
        if ($de->greaterThan($ate)) {
            [$de, $ate] = [$ate, $de];
        }
        if ($de->diffInDays($ate) > self::DIAS_MAXIMO) {
            $de = $ate->copy()->subDays(self::DIAS_MAXIMO);
        }

        return [$de, $ate];
    }
}
