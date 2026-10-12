<?php

namespace App\Http\Middleware;

use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Analytics\Support\OrigemVisita;
use App\Support\Privacidade\Cookies;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registro de visitas da loja. Desde 2026-10-10 segue o aviso de cookies
 * (docs/privacidade-e-cookies.md):
 *
 * - Sempre: IP, data/hora e página — é o registro de acesso que o Marco
 *   Civil da Internet (Lei 12.965/2014, art. 15) obriga a loja a guardar
 *   por 6 meses. Sem o OK, o "visitante" é um código tirado da sessão (sem
 *   cookie novo) e o navegador fica de fora.
 * - Com o OK: cookie de visitante (kazakora_visitor), navegador completo e
 *   os cookies permitidos do visitante (Cookies::COOKIES_REGISTRAVEIS,
 *   coluna site_visits.cookies, pedido 2026-10-12). Sem o OK nenhum cookie
 *   é gravado.
 * - Em todos: origem (UTM), aparelho (celular/tablet/computador) e o site
 *   de onde veio sem o "?..." (pode ter dado pessoal).
 * - Em todos: a origem classificada (OrigemVisita: Instagram, Google Ads,
 *   Direto, Interno...) e o nome do parâmetro de clique (fbclid, gclid...).
 *   O valor do clique só com o OK (pedido 2026-10-12).
 *
 * - Contagem de visitantes (painel e Indicadores, pedido 2026-10-11): o
 *   cookie kazakora_visitor é o visitante; sem o OK, um por IP no dia, e o
 *   IP que deu OK no dia entra no cookie (SiteVisit::visitantesPorDia). A
 *   página do OK conta (registrarOk).
 *
 * Depois de 6 meses o IP e o navegador são apagados (privacidade:limpar).
 */
class TrackSiteVisit
{
    private const VISITOR_COOKIE = Cookies::COOKIE_VISITANTE;

    /**
     * Robôs e programas que não são navegador (pedido 2026-10-12: curl,
     * scanners e monitores entravam como visita "Direto").
     */
    public const BOT_PATTERN = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|curl|wget|python|go-http|java\/|okhttp|axios|node-fetch|libwww|httpclient|headless|scan|censys|zgrab|lighthouse|uptime/i';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldTrack($request, $response)) {
            rescue(fn () => $this->registrar($request), null, false);
        }

        return $response;
    }

    private function registrar(Request $request): void
    {
        $consentiu = Cookies::aceitou($request);

        if ($consentiu) {
            $visitorId = $request->cookie(self::VISITOR_COOKIE) ?: (string) Str::uuid();

            if (! $request->cookie(self::VISITOR_COOKIE)) {
                Cookie::queue(self::VISITOR_COOKIE, $visitorId, Cookies::DURACAO_MINUTOS);
            }
        } else {
            $visitorId = 's-'.substr(hash('sha256', $request->session()->getId()), 0, 34);
        }

        $referer = $request->headers->get('referer');

        self::gravar($request, $visitorId, $consentiu, $request->path(), $this->origem($referer), [
            'utm_source' => $this->utm($request, 'utm_source', 80),
            'utm_medium' => $this->utm($request, 'utm_medium', 80),
            'utm_campaign' => $this->utm($request, 'utm_campaign', 120),
        ], $referer, OrigemVisita::clickId($request->query()));
    }

    /**
     * Visita contável da página em que o cliente deu OK (CookiesController):
     * sem ela, quem dá OK e sai sem abrir outra página nunca entra na conta.
     */
    public static function registrarOk(Request $request, string $visitorId): void
    {
        $pagina = parse_url((string) $request->headers->get('referer'));
        $dominio = preg_replace('/^www\./', '', (string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if (empty($pagina['host']) || ($dominio !== '' && preg_replace('/^www\./', '', $pagina['host']) !== $dominio)
            || str_starts_with($pagina['path'] ?? '/', '/admin') || self::naoENavegador($request)) {
            return;
        }

        $query = [];
        parse_str($pagina['query'] ?? '', $query);
        $utm = fn (string $campo, int $limite) => is_string($query[$campo] ?? null) && $query[$campo] !== '' ? Str::limit(strip_tags($query[$campo]), $limite, '') : null;

        // O Referer aqui é a própria página da loja: sem UTM/clique, a origem é Interno.
        rescue(fn () => self::gravar($request, $visitorId, true, $pagina['path'] ?? '/', null, [
            'utm_source' => $utm('utm_source', 80),
            'utm_medium' => $utm('utm_medium', 80),
            'utm_campaign' => $utm('utm_campaign', 120),
        ], (string) $request->headers->get('referer'), OrigemVisita::clickId($query), [
            // Estão sendo criados nesta mesma resposta (CookiesController).
            Cookies::COOKIE_VISITANTE => $visitorId,
            Cookies::COOKIE_OK => Cookies::VERSAO,
        ]), null, false);
    }

    /**
     * @param  array<string, ?string>  $utm
     * @param  ?string  $refererCompleto  Referer como veio, só para classificar a origem (não é gravado)
     * @param  array{0: ?string, 1: ?string}  $clique  [parâmetro, valor] de OrigemVisita::clickId
     * @param  array<string, string>  $cookiesNovos  cookies criados nesta resposta, ainda não enviados pelo navegador
     */
    private static function gravar(Request $request, string $visitorId, bool $consentiu, string $path, ?string $referer, array $utm, ?string $refererCompleto = null, array $clique = [null, null], array $cookiesNovos = []): void
    {
        $cookies = $consentiu ? [...Cookies::doVisitante($request), ...$cookiesNovos] : [];
        ksort($cookies);

        SiteVisit::create([
            'site' => SiteVisit::SITE_LOJA,
            'cookies' => $cookies === [] ? null : $cookies,
            'visitor_id' => $visitorId,
            'user_id' => $request->user()?->id,
            'path' => Str::limit('/'.ltrim($path, '/'), 250, ''),
            'referer' => $referer,
            'user_agent' => $consentiu ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'ip' => $request->ip(),
            ...$utm,
            'origem' => OrigemVisita::classificar($refererCompleto, $utm, $clique[0]),
            'click_id_param' => $clique[0],
            'click_id' => $consentiu ? $clique[1] : null,
            'dispositivo' => self::dispositivo((string) $request->userAgent()),
            'consentiu' => $consentiu,
        ]);
    }

    /** Site de onde veio, sem o "?..." (busca, e-mail e afins ficam de fora). */
    private function origem(?string $referer): ?string
    {
        if (! $referer) {
            return null;
        }

        $partes = parse_url($referer);
        if (empty($partes['host'])) {
            return null;
        }

        return Str::limit(($partes['scheme'] ?? 'https').'://'.$partes['host'].($partes['path'] ?? ''), 250, '');
    }

    private function utm(Request $request, string $campo, int $limite): ?string
    {
        $valor = $request->query($campo);

        return is_string($valor) && $valor !== '' ? Str::limit(strip_tags($valor), $limite, '') : null;
    }

    private static function dispositivo(string $userAgent): string
    {
        return match (true) {
            (bool) preg_match('/ipad|tablet|kindle|playbook|silk/i', $userAgent) => 'tablet',
            (bool) preg_match('/mobi|android|iphone|ipod|opera mini|iemobile/i', $userAgent) => 'celular',
            default => 'computador',
        };
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! $request->isMethod('get') || $response->getStatusCode() !== 200) {
            return false;
        }

        // Só o site da loja (pedido 2026-10-11: "é somente o kazakora.com,
        // não misturar com nenhuma plataforma"): acesso pelo IP, pelo
        // homolog ou por outro domínio apontado pra cá não conta.
        $dominio = preg_replace('/^www\./', '', (string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($dominio !== '' && preg_replace('/^www\./', '', $request->getHost()) !== $dominio) {
            return false;
        }

        if ($request->is('admin*') || $request->is('build/*') || $request->is('storage/*')) {
            return false;
        }

        if ($request->headers->has('X-Inertia-Partial-Component')) {
            return false;
        }

        return ! self::naoENavegador($request);
    }

    /**
     * Todo navegador de verdade se apresenta como "Mozilla/..." e, quando
     * manda Referer http(s), manda com caminho ("https://site.com/"). Referer
     * sem caminho ("http://kazakora.com") é robô fingindo que veio da loja.
     */
    private static function naoENavegador(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();

        if (! str_starts_with($userAgent, 'Mozilla/') || preg_match(self::BOT_PATTERN, $userAgent) === 1) {
            return true;
        }

        $referer = (string) $request->headers->get('referer');

        return preg_match('#^https?://[^/?\#]+$#i', $referer) === 1;
    }
}
