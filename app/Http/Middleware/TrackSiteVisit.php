<?php

namespace App\Http\Middleware;

use App\Modules\Analytics\Models\SiteVisit;
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
 * - Com o OK: cookie de visitante (kazakora_visitor) e navegador completo.
 * - Em todos: origem (UTM), aparelho (celular/tablet/computador) e o site
 *   de onde veio sem o "?..." (pode ter dado pessoal).
 *
 * Depois de 6 meses o IP e o navegador são apagados (privacidade:limpar).
 */
class TrackSiteVisit
{
    private const VISITOR_COOKIE = Cookies::COOKIE_VISITANTE;

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp/i';

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

        SiteVisit::create([
            'visitor_id' => $visitorId,
            'user_id' => $request->user()?->id,
            'path' => Str::limit('/'.ltrim($request->path(), '/'), 250, ''),
            'referer' => $this->origem($request->headers->get('referer')),
            'user_agent' => $consentiu ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'ip' => $request->ip(),
            'utm_source' => $this->utm($request, 'utm_source', 80),
            'utm_medium' => $this->utm($request, 'utm_medium', 80),
            'utm_campaign' => $this->utm($request, 'utm_campaign', 120),
            'dispositivo' => $this->dispositivo((string) $request->userAgent()),
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

    private function dispositivo(string $userAgent): string
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

        if ($request->is('admin*') || $request->is('build/*') || $request->is('storage/*')) {
            return false;
        }

        if ($request->headers->has('X-Inertia-Partial-Component')) {
            return false;
        }

        $userAgent = (string) $request->userAgent();

        if ($userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1) {
            return false;
        }

        return true;
    }
}
