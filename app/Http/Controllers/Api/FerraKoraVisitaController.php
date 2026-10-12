<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Models\FerraKoraVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Visitas do site FerraKora (pedido 2026-10-12). O site é outro (Hostinger)
 * e avisa cada página aberta direto do navegador do visitante, assim o IP
 * gravado é o do visitante e não o do servidor da Hostinger:
 *
 *   <script>navigator.sendBeacon('https://kazakora.com/api/ferrakora/visita?p='
 *     + encodeURIComponent(location.pathname + location.search));</script>
 *
 * Só aceita chamadas que vêm dos domínios em services.ferrakora.origens
 * (cabeçalho Origin, ou Referer se o navegador não mandar Origin). Robô e
 * programa que não é navegador ficam de fora (mesma regra da loja). Grava
 * IP, data/hora, página e navegador; responde 204 sempre que aceita.
 */
class FerraKoraVisitaController extends Controller
{
    public function registrar(Request $request): Response
    {
        if (! $this->origemPermitida($request)) {
            return response('', 403);
        }

        $userAgent = (string) $request->userAgent();
        if (str_starts_with($userAgent, 'Mozilla/') && preg_match(TrackSiteVisit::BOT_PATTERN, $userAgent) !== 1) {
            $pagina = $request->query('p');
            if (! is_string($pagina) || $pagina === '') {
                $pagina = (string) (parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: '/');
            }

            FerraKoraVisit::query()->create([
                'ip' => $request->ip(),
                'path' => Str::limit('/'.ltrim(strip_tags($pagina), '/'), 250, ''),
                'user_agent' => Str::limit($userAgent, 250, ''),
            ]);
        }

        return response('', 204);
    }

    /** O CORS de /api/* já é liberado pelo Laravel (config padrão); a trava é aqui. */
    private function origemPermitida(Request $request): bool
    {
        $url = (string) ($request->headers->get('origin') ?: $request->headers->get('referer'));
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

        $permitidos = array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', (string) config('services.ferrakora.origens')),
        ));

        return $host !== '' && in_array($host, $permitidos, true);
    }
}
