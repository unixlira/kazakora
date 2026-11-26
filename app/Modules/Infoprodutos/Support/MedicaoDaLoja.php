<?php

namespace App\Modules\Infoprodutos\Support;

use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Infoprodutos\Http\Controllers\ColetaController;
use Illuminate\Http\Request;

/**
 * Página da loja medida pelo mapa de cliques (/js/kzm.js), pedido do Lira em
 * 2026-11-26: "se teve 1 visita do anúncio, eu quero as marcas da tela".
 * Antes só a página de produto V2 carregava o script, e só com o navegador
 * ocioso; a home, as listas, o carrinho e o blog não eram medidos.
 *
 * A chave é a MESMA das visitas (PaginasDoSite::chave sobre o caminho que
 * TrackSiteVisit grava), para a tela Comportamento mostrar as visitas do
 * site e as visitas medidas lado a lado:
 *  - produto: ['kz/<slug>', 'produto'];
 *  - outra página: ['kzp[/caminho]', 'pagina'] (início = kzp).
 *
 * Fora: admin, outro domínio (homolog, IP) e o checkout (/finalizacao,
 * /pagamento), que tem medição própria (medicaoCheckout.js →
 * checkout_eventos) e campos de pagamento.
 */
class MedicaoDaLoja
{
    /** @return array{p: string, pg: string}|null */
    public static function daRequisicao(Request $request): ?array
    {
        if ($request->is('admin*') || $request->is('finalizacao*') || $request->is('pagamento*')
            || ! TrackSiteVisit::dominioDaLoja($request)) {
            return null;
        }

        return self::doCaminho($request->path(), $request->query());
    }

    /** @return array{p: string, pg: string}|null */
    public static function doCaminho(string $path, array $query = []): ?array
    {
        $chave = PaginasDoSite::chave('kazakora', TrackSiteVisit::caminho($path, $query));
        if ($chave === null || ! ColetaController::daLoja($chave[0])) {
            return null;
        }

        return ['p' => $chave[0], 'pg' => $chave[1]];
    }
}
