<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Espelho web (dentro do admin, autenticado por sessão de staff) do painel
 * que o KoraSync (app desktop Windows) já mostra pro operador de
 * separação/expedição — pedido explícito 2026-08-31: "cria um menu
 * Korasync no Kazakora... igualzinho, inclusive com os mesmos comandos".
 *
 * Esta classe só serve a página Inertia (casca fixa + qual aba abre por
 * padrão) — os dados de verdade (fila, sem estoque, vendas futuras,
 * separados, cancelados, métricas do dia, embalar pedido, foto do produto)
 * são os MESMOS endpoints que o KoraSync já consome, reaproveitados direto
 * de App\Http\Controllers\Api\DashboardAgentController via as rotas
 * korasync.api.* (ver routes/web.php) — nenhuma lógica de negócio é
 * duplicada aqui. A diferença entre os dois consumidores é só a
 * autenticação: o app desktop usa token fixo (middleware print.agent), esta
 * tela usa a sessão normal do admin logado (auth+staff+permission, mesmo
 * grupo de rotas de qualquer outra página do admin).
 */
class KoraSyncController extends Controller
{
    private const TABS = ['fila', 'sem-estoque', 'vendas-futuras', 'separados', 'cancelados'];

    public function index(Request $request, string $tab = 'fila'): Response
    {
        if (! in_array($tab, self::TABS, true)) {
            abort(404);
        }

        return Inertia::render('Admin/KoraSync/Index', [
            'initialTab' => $tab,
        ]);
    }
}
