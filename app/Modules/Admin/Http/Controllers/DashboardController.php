<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Rbac\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entrada do admin (/admin, rota admin.painel).
 *
 * Desde 2026-10-19 (Lira aprovou a proposta do Fluxo de Caixa) a tela
 * Gerenciador deixou de existir: quem vê dinheiro (financeiro.view) cai no
 * Fluxo de Caixa; quem não vê, não pode ver lucro, então vai para
 * Indicadores (relatorios.view) ou Pedidos (toda a equipe vê). O conteúdo
 * do Gerenciador foi para Indicadores, em abas (ver
 * Support\Indicadores\PainelGerencial). A query string vai junto; o link
 * antigo dos acessos (/admin?mes=AAAA-MM) abre a aba Acessos ao site.
 */
class DashboardController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($request->filled('mes') && Permissions::has($user, 'relatorios.view')) {
            return redirect()->to('/admin/indicadores?'.http_build_query(['aba' => 'acessos', 'mes' => (string) $request->query('mes')]));
        }

        $destino = match (true) {
            Permissions::has($user, 'financeiro.view') => '/admin/fluxo-de-caixa',
            Permissions::has($user, 'relatorios.view') => '/admin/indicadores',
            // Pedidos não tem permissão própria na rota: toda a equipe vê.
            default => '/admin/pedidos',
        };

        $query = (string) $request->server('QUERY_STRING');

        return redirect()->to($destino.($query ? '?'.$query : ''));
    }
}
