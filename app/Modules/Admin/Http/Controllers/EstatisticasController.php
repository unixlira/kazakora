<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\CurvaAbc;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Estatísticas (pedido do Lira 2026-10-12): curva ABC dos produtos de
 * cada marketplace, com lucro pela conta oficial (ContributionMargin) e
 * tendência contra o período anterior. Uma tela por canal. Saiu do menu
 * Oportunidades, que ficou só com dado de mercado (OportunidadesController).
 */
class EstatisticasController extends Controller
{
    public const CANAIS = [
        'mercado-livre' => ['origin' => Order::ORIGIN_MERCADO_LIVRE, 'nome' => 'Mercado Livre'],
        'shopee' => ['origin' => Order::ORIGIN_SHOPEE, 'nome' => 'Shopee'],
    ];

    /** Período padrão: últimos 30 dias, hoje incluído. */
    private const DIAS_PADRAO = 30;

    /** Teto do filtro, para a tela não varrer anos de pedido. */
    private const DIAS_MAXIMO = 366;

    public function curvaAbc(Request $request, string $canal, CurvaAbc $curva): Response
    {
        abort_unless(isset(self::CANAIS[$canal]), 404);

        [$de, $ate] = $this->periodo($request);

        return Inertia::render('Admin/Estatisticas/CurvaAbc', [
            'canal' => ['slug' => $canal, 'nome' => self::CANAIS[$canal]['nome'], 'origin' => self::CANAIS[$canal]['origin']],
            'filtros' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'curva' => $curva->analisar($de, $ate->copy()->addDay(), self::CANAIS[$canal]['origin']),
            'limites' => ['a' => CurvaAbc::LIMITE_A, 'b' => CurvaAbc::LIMITE_B],
        ]);
    }

    /**
     * Datas do filtro (?de=AAAA-MM-DD&ate=AAAA-MM-DD, as duas incluídas) no
     * fuso da loja. Data inválida volta para o padrão; data invertida troca.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodo(Request $request): array
    {
        $hoje = Carbon::today();
        $ler = function (?string $valor) {
            if (! $valor || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
                return null;
            }
            [$ano, $mes, $dia] = array_map('intval', explode('-', $valor));
            if (! checkdate($mes, $dia, $ano)) {
                return null;
            }

            return Carbon::create($ano, $mes, $dia)->startOfDay();
        };

        $ate = $ler($request->query('ate')) ?? $hoje->copy();
        $de = $ler($request->query('de')) ?? $ate->copy()->subDays(self::DIAS_PADRAO - 1);
        if ($de->greaterThan($ate)) {
            [$de, $ate] = [$ate, $de];
        }
        if ($de->diffInDays($ate) > self::DIAS_MAXIMO) {
            $de = $ate->copy()->subDays(self::DIAS_MAXIMO);
        }

        return [$de, $ate];
    }
}
