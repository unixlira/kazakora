<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Models\User;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /selos/clique (pedido do Lira 2026-10-25): o rodapé avisa quando o
 * visitante clica num selo de segurança. Soma 1 na última visita da loja
 * daquele visitante (mesma regra de visitante do checkout: cookie com OK, ou
 * código da sessão sem OK).
 *
 * - Corpo: JSON em text/plain (sendBeacon), {"selo": "google|reclame_aqui|norton"}.
 * - Fora do CSRF (sendBeacon não manda o X-XSRF-TOKEN); só aceita a própria
 *   loja (Origin/Sec-Fetch-Site), tem limite por minuto e só incrementa contagem.
 * - Robô, outro domínio e equipe: 204 sem gravar (mesma regra do painel de visitas).
 */
class SeloCliqueController extends Controller
{
    public const COLUNAS = [
        'google' => 'cliques_selo_google',
        'reclame_aqui' => 'cliques_selo_reclame_aqui',
        'norton' => 'cliques_selo_norton',
    ];

    public function store(Request $request): Response
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        $origem = $request->headers->get('Origin');
        if (($site !== null && ! in_array($site, ['same-origin', 'none'], true))
            || ($origem !== null && parse_url($origem, PHP_URL_HOST) !== $request->getHost())) {
            return response()->noContent(403);
        }

        $dados = json_decode((string) $request->getContent(), true);
        $selo = is_array($dados) ? ($dados['selo'] ?? null) : null;
        if (strlen((string) $request->getContent()) > 200 || ! is_string($selo) || ! isset(self::COLUNAS[$selo])) {
            return response()->noContent(422);
        }

        if (! TrackSiteVisit::dominioDaLoja($request) || TrackSiteVisit::naoENavegador($request)) {
            return response()->noContent();
        }

        $usuario = $request->user();
        if ($usuario && in_array($usuario->role, User::STAFF_ROLES, true)) {
            return response()->noContent();
        }

        $visita = SiteVisit::query()->daLoja()
            ->where('visitor_id', CheckoutEvento::visitante($request))
            ->latest('id')
            ->first();

        $visita?->increment(self::COLUNAS[$selo]);

        return response()->noContent();
    }
}
