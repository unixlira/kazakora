<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Http\Controllers\CheckoutEventosController;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/{site}/checkout/eventos (pedido do Lira 2026-11-14): a tela de
 * pagamento da FerraKora manda o que a pessoa fez nela, igual à /finalizacao
 * da loja (CheckoutEventosController): mesmo corpo (JSON em text/plain, até
 * 8 KB e 30 eventos), mesma lista FECHADA de eventos e campos, nunca o que
 * foi digitado. Grava em checkout_eventos com a coluna `site`.
 *
 * - Só aceita chamada dos domínios em services.{site}.origens (Origin, ou
 *   Referer se o navegador não mandar Origin), como a rota de visita.
 * - Visitante igual ao das visitas do site (OutroSiteVisitaController): sem
 *   cookie em outro domínio, é o código tirado do IP. O IP não é gravado.
 * - Robô: responde 204 e não grava.
 * - Leitura: php artisan checkout:funil --site=ferrakora.
 */
class OutroSiteCheckoutEventosController extends Controller
{
    public function registrar(Request $request, string $site, CheckoutEventosController $loja): Response
    {
        abort_unless(array_key_exists($site, SiteVisit::OUTROS_SITES), 404);

        if (! $this->origemPermitida($request, $site)) {
            return response()->noContent(403);
        }

        $corpo = (string) $request->getContent();
        if (strlen($corpo) > CheckoutEventosController::LIMITE_BYTES) {
            return response()->noContent(413);
        }

        $dados = json_decode($corpo, true);
        $eventos = $loja->validar($dados);
        if ($eventos === null) {
            return response()->noContent(422);
        }

        $userAgent = (string) $request->userAgent();
        if (! str_starts_with($userAgent, 'Mozilla/') || preg_match(TrackSiteVisit::BOT_PATTERN, $userAgent) === 1) {
            return response()->noContent();
        }

        $visitante = CheckoutEvento::visitanteDeOutroSite($request);
        $base = [
            'site' => $site,
            'visitor_id' => $visitante,
            'pagina_id' => $dados['pagina'],
            'dispositivo' => TrackSiteVisit::dispositivo($userAgent),
        ];
        $origem = collect($eventos)->contains('evento', 'abriu') ? CheckoutEvento::origemDaVisita($visitante, $site) : null;

        foreach ($eventos as $evento) {
            CheckoutEvento::create([
                ...$base,
                'evento' => $evento['evento'],
                'campo' => $evento['campo'] ?? null,
                'valor' => $evento['valor'] ?? $evento['segundos'] ?? null,
                'rolagem' => $evento['rolagem'] ?? null,
                'origem' => $evento['evento'] === 'abriu' ? $origem : null,
            ]);
        }

        return response()->noContent();
    }

    private function origemPermitida(Request $request, string $site): bool
    {
        $url = (string) ($request->headers->get('origin') ?: $request->headers->get('referer'));
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $dominios = array_filter(array_map(
            fn ($d) => preg_replace('/^www\./', '', strtolower(trim($d))),
            explode(',', (string) config("services.{$site}.origens")),
        ));

        return $host !== '' && in_array($host, $dominios, true);
    }
}
