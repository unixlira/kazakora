<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Visitas dos outros sites do grupo (FerraKora e Beauty Kora, pedido
 * 2026-10-12). O site é outro (Hostinger) e avisa cada página aberta direto
 * do navegador do visitante, assim o IP gravado é o do visitante e não o do
 * servidor da Hostinger:
 *
 *   <script>navigator.sendBeacon('https://kazakora.com/api/ferrakora/visita?p='
 *     + encodeURIComponent(location.pathname + location.search));</script>
 *
 * Grava em site_visits com site = ferrakora/beautykora (banco compartilhado,
 * a loja só lê site = kazakora). Só aceita chamadas que vêm dos domínios em
 * services.{site}.origens (cabeçalho Origin, ou Referer se o navegador não
 * mandar Origin). Robô e programa que não é navegador ficam de fora (mesma
 * regra da loja). Grava IP, data/hora, página e navegador; responde 204
 * sempre que aceita.
 */
class OutroSiteVisitaController extends Controller
{
    public function registrar(Request $request, string $site): Response
    {
        abort_unless(array_key_exists($site, SiteVisit::OUTROS_SITES), 404);

        if (! $this->origemPermitida($request, $site)) {
            return response('', 403);
        }

        $userAgent = (string) $request->userAgent();
        if (str_starts_with($userAgent, 'Mozilla/') && preg_match(TrackSiteVisit::BOT_PATTERN, $userAgent) !== 1) {
            $pagina = $request->query('p');
            if (! is_string($pagina) || $pagina === '') {
                $pagina = (string) (parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: '/');
            }

            $ip = $request->ip();
            SiteVisit::query()->create([
                'site' => $site,
                // Sem cookie em outro domínio: o visitante é o IP.
                'visitor_id' => $ip !== null ? substr(md5($ip), 0, 32) : 'anonimo',
                'ip' => $ip,
                'path' => Str::limit('/'.ltrim(strip_tags($pagina), '/'), 250, ''),
                'user_agent' => Str::limit($userAgent, 250, ''),
                'consentiu' => false,
            ]);
        }

        return response('', 204);
    }

    /** O CORS de /api/* já é liberado pelo Laravel (config padrão); a trava é aqui. */
    private function origemPermitida(Request $request, string $site): bool
    {
        $url = (string) ($request->headers->get('origin') ?: $request->headers->get('referer'));
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

        $permitidos = array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', (string) config("services.{$site}.origens")),
        ));

        return $host !== '' && in_array($host, $permitidos, true);
    }
}
