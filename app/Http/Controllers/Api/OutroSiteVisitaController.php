<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackSiteVisit;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Analytics\Support\GeoDoVisitante;
use App\Modules\Analytics\Support\LocalizacaoDaVisita;
use App\Modules\Analytics\Support\OrigemVisita;
use App\Modules\Analytics\Support\PrecoVisto;
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
 *
 * Origem e Pixel (2026-11-11: as visitas dos anúncios da FerraKora apareciam
 * todas como "Interno" e sem pixel). O site manda junto:
 *  - e=1 na 1ª página da aba, com ref (document.referrer), utm_* e o
 *    parâmetro de clique (fbclid, gclid...): vira a origem, igual à loja;
 *  - e=0 nas páginas seguintes: origem Interno;
 *  - fbp/fbc: os cookies _fbp e _fbc do Pixel da Meta, que o site carrega
 *    sem aviso de cookies (docs/privacidade-e-cookies.md, "FerraKora").
 * Sem "e" (versão antiga do site em cache) grava como antes, sem origem.
 *
 * Valor (2026-11-12): na página /produto/<slug> do FerraKora a visita guarda
 * o preço que a página mostra a este visitante (PrecoVisto), lido aqui do
 * mesmo catálogo que o site consulta; o site não manda nada a mais.
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
            [$clickParam, $clickValor] = OrigemVisita::clickId($request->query());
            $entrada = $request->query('e');
            $utm = [
                'utm_source' => $this->texto($request, 'utm_source', 80),
                'utm_medium' => $this->texto($request, 'utm_medium', 80),
                'utm_campaign' => $this->texto($request, 'utm_campaign', 120),
            ];
            $referer = $entrada === '1' ? $this->refererDeFora($request, $site) : null;

            SiteVisit::query()->create([
                'site' => $site,
                // Sem cookie em outro domínio: o visitante é o IP.
                'visitor_id' => $ip !== null ? substr(md5($ip), 0, 32) : 'anonimo',
                'ip' => $ip,
                // O aviso passa pela Cloudflare do kazakora.com: localização do visitante.
                ...LocalizacaoDaVisita::doRequest($request),
                'path' => $caminho = Str::limit('/'.ltrim(strip_tags($pagina), '/'), 250, ''),
                // Página de produto do FerraKora: o preço que ela mostra a este visitante (2026-11-12).
                'preco_visto' => $site === 'ferrakora' ? rescue(fn () => PrecoVisto::doFerraKora($caminho), null, false) : null,
                'user_agent' => Str::limit($userAgent, 250, ''),
                'dispositivo' => TrackSiteVisit::dispositivo($userAgent),
                'consentiu' => false,
                ...$utm,
                'referer' => $referer !== null ? Str::limit(preg_replace('/[?#].*$/', '', $referer), 250, '') : null,
                'origem' => match ($entrada) {
                    '1' => OrigemVisita::classificar($referer, $utm, $clickParam),
                    '0' => 'interno',
                    default => null,
                },
                'click_id_param' => $clickParam,
                // Mesma regra da loja: só o fbclid tem o valor guardado sem aceite.
                'click_id' => $clickParam === 'fbclid' ? $clickValor : null,
                'cookies' => $this->cookiesDoPixel($request),
            ]);

            // Lugar aproximado do IP, igual à loja (2026-10-24); a "sessão" é o visitante.
            if ($ip !== null) {
                $visitante = substr(md5($ip), 0, 32);
                rescue(fn () => app(GeoDoVisitante::class)->registrar($request, $site, $visitante, $visitante), null, false);
            }
        }

        return response('', 204);
    }

    private function texto(Request $request, string $campo, int $limite): ?string
    {
        $valor = $request->query($campo);

        return is_string($valor) && trim($valor) !== '' ? Str::limit(strip_tags(trim($valor)), $limite, '') : null;
    }

    /** Site de onde veio a 1ª página; o próprio site não conta (vira Direto). */
    private function refererDeFora(Request $request, string $site): ?string
    {
        $ref = $request->query('ref');
        if (! is_string($ref) || ! preg_match('#^(https?|android-app)://#i', $ref)) {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($ref, PHP_URL_HOST)));

        return in_array($host, $this->dominios($site), true) ? null : Str::limit($ref, 500, '');
    }

    /** _fbp e _fbc do Pixel da Meta, no formato fb.1.<hora>.<valor>. */
    private function cookiesDoPixel(Request $request): ?array
    {
        $cookies = [];
        foreach (['fbc' => '_fbc', 'fbp' => '_fbp'] as $campo => $nome) {
            $valor = $request->query($campo);
            if (is_string($valor) && preg_match('/^fb\.\d\.\d{10,13}\.[A-Za-z0-9._\-]{1,500}$/', $valor) === 1) {
                $cookies[$nome] = $valor;
            }
        }

        return $cookies === [] ? null : $cookies;
    }

    /** @return list<string> */
    private function dominios(string $site): array
    {
        return array_values(array_filter(array_map(
            fn ($d) => preg_replace('/^www\./', '', strtolower(trim($d))),
            explode(',', (string) config("services.{$site}.origens")),
        )));
    }

    /** O CORS de /api/* já é liberado pelo Laravel (config padrão); a trava é aqui. */
    private function origemPermitida(Request $request, string $site): bool
    {
        $url = (string) ($request->headers->get('origin') ?: $request->headers->get('referer'));
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

        return $host !== '' && in_array($host, $this->dominios($site), true);
    }
}
