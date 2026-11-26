<?php

namespace App\Modules\Analytics\Support;

use App\Http\Middleware\TrackSiteVisit;
use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Visita que não conta (pedido do Lira em 26/11/2026: a contagem tinha as
 * visitas dele mesmo e as de robôs, "limpa isso"). Um lugar só para a regra,
 * usado na gravação (TrackSiteVisit, OutroSiteVisitaController) e na limpeza
 * do histórico (visitas:limpar):
 *
 * - robo: navegador que não é navegador (sem "Mozilla/", robô, crawler,
 *   prévia de link, curl, python, headless, Lighthouse, monitores — padrão em
 *   TrackSiteVisit::BOT_PATTERN), ou IP da rede da Meta/Google que confere
 *   anúncios e páginas com cara de celular. Na gravação nem é gravado.
 * - equipe: usuário logado com papel de equipe (User::STAFF_ROLES) ou o
 *   navegador (cookie kazakora_visitor) em que alguém da equipe já entrou.
 * - ip_ignorado: IP da lista fixa (VISITAS_IPS_IGNORADOS, COMPORTAMENTO_IPS_EQUIPE)
 *   e os nossos robôs (VPS da Hostinger, a própria máquina).
 * - casa_equipe: mesma rede de um navegador da equipe nos últimos dias
 *   (config visitas.dias_casa): o /64 do IPv6 (a casa inteira; a operadora
 *   troca o prefixo a cada poucos dias) ou o IPv4 exato. Pega o celular do
 *   dono deslogado, a Cora Box e as abas abertas sem login.
 * - fora_do_brasil (config visitas.so_brasil): IP de outro país (Cloudflare
 *   CF-IPCountry ou a base DB-IP do servidor). A loja só vende no Brasil e,
 *   de 19 a 26/11/2026, mais da metade dos IPs que visitaram eram de
 *   servidores nos EUA, Singapura, China, Europa (Google Cloud, Tencent,
 *   Alibaba) abrindo só a home com navegador comum. País desconhecido conta.
 * - robo_volume (só no histórico): o mesmo visitante com mais de
 *   SiteVisit::VISITAS_DIA_ROBO páginas num dia.
 *
 * A linha de equipe/IP/casa é gravada com site_visits.ignorada = motivo e o
 * model SiteVisit não a mostra em consulta nenhuma (escopo global).
 */
class VisitaIgnorada
{
    public const ROBO = 'robo';

    public const EQUIPE = 'equipe';

    public const IP_IGNORADO = 'ip_ignorado';

    public const CASA_EQUIPE = 'casa_equipe';

    public const ROBO_VOLUME = 'robo_volume';

    public const FORA_DO_BRASIL = 'fora_do_brasil';

    /** Motivo => rótulo, na ordem em que a regra confere. */
    public const MOTIVOS = [
        self::ROBO => 'Robô (navegador ou rede de robô)',
        self::EQUIPE => 'Equipe logada / navegador da equipe',
        self::IP_IGNORADO => 'IP da lista (nossos robôs e IPs fixos)',
        self::CASA_EQUIPE => 'Mesma rede da equipe (casa do dono)',
        self::FORA_DO_BRASIL => 'IP fora do Brasil (servidores no exterior)',
        self::ROBO_VOLUME => 'Mais de 300 páginas num dia',
    ];

    /**
     * Robôs do Google que abrem a página com navegador comum (Chrome sem
     * "bot" no nome): 91 visitas de 66.249.73.99 até 21/11/2026.
     */
    public const REDES_DO_GOOGLE = ['66.249.64.0/19', '2001:4860:4801::/48'];

    private const CACHE = 'visitas:equipe';

    /** Motivo para não contar a visita desta requisição, ou null quando conta. */
    public static function daRequisicao(Request $request, ?string $visitorId = null): ?string
    {
        if (self::navegadorDeRobo($request->userAgent()) || self::redeDeRobo($request->ip())) {
            return self::ROBO;
        }

        if ($request->user() instanceof User && $request->user()->isStaff()) {
            if ($visitorId !== null && ! in_array($visitorId, self::dados()['navegadores'], true)) {
                self::esquecer();
            } elseif (($chave = self::rede($request->ip())) !== null && ! in_array($chave, self::dados()['redes'], true)) {
                self::esquecer();
            }

            return self::EQUIPE;
        }

        if ($visitorId !== null && in_array($visitorId, self::dados()['navegadores'], true)) {
            // Rede nova da casa (a operadora trocou o prefixo): passa a valer já.
            if (($chave = self::rede($request->ip())) !== null && ! in_array($chave, self::dados()['redes'], true)) {
                self::esquecer();
            }

            return self::EQUIPE;
        }

        return self::doIp($request->ip(), $request->header('CF-IPCountry'));
    }

    /** Motivo só pelo IP (outros sites, sem cookie nem login), ou null. */
    public static function doIp(?string $ip, ?string $paisDaCloudflare = null): ?string
    {
        if (self::redeDeRobo($ip)) {
            return self::ROBO;
        }
        if (self::ipDaLista($ip)) {
            return self::IP_IGNORADO;
        }
        $chave = self::rede($ip);
        if ($chave !== null && in_array($chave, self::dados()['redes'], true)) {
            return self::CASA_EQUIPE;
        }

        return self::foraDoBrasil($ip, $paisDaCloudflare) ? self::FORA_DO_BRASIL : null;
    }

    /** País conhecido e diferente de BR (com a regra ligada). */
    public static function foraDoBrasil(?string $ip, ?string $paisDaCloudflare = null): bool
    {
        if (! config('visitas.so_brasil', true)) {
            return false;
        }
        $pais = strtoupper(trim((string) $paisDaCloudflare));
        if (preg_match('/^[A-Z]{2}$/', $pais) !== 1 || in_array($pais, ['XX', 'T1'], true)) {
            $pais = ($ip !== null && $ip !== '') ? strtoupper((string) (self::geo()->buscar($ip)['pais'] ?? '')) : '';
        }

        return preg_match('/^[A-Z]{2}$/', $pais) === 1 && $pais !== 'BR';
    }

    private static ?LocalDoIp $geo = null;

    private static function geo(): LocalDoIp
    {
        return self::$geo ??= new LocalDoIp;
    }

    /** Sem navegador, robô, prévia de link, programa ou monitor. */
    public static function navegadorDeRobo(?string $userAgent): bool
    {
        $userAgent = (string) $userAgent;

        return ! str_starts_with($userAgent, 'Mozilla/') || preg_match(TrackSiteVisit::BOT_PATTERN, $userAgent) === 1;
    }

    /** Rede da Meta ou do Google (robôs com cara de celular). */
    public static function redeDeRobo(?string $ip): bool
    {
        return $ip !== null && $ip !== '' && IpUtils::checkIp($ip, [...TrackSiteVisit::REDES_DA_META, ...self::REDES_DO_GOOGLE]);
    }

    /** IP da lista fixa: VISITAS_IPS_IGNORADOS, COMPORTAMENTO_IPS_EQUIPE e os nossos servidores. */
    public static function ipDaLista(?string $ip): bool
    {
        $lista = self::lista();

        return $ip !== null && $ip !== '' && $lista !== [] && IpUtils::checkIp($ip, $lista);
    }

    /** @return list<string> */
    public static function lista(): array
    {
        $texto = fn ($v) => is_string($v) && trim($v) !== '';

        return array_values(array_unique(array_map('trim', array_filter([
            ...(array) config('visitas.ips_proprios', []),
            ...(array) config('visitas.ips_ignorados', []),
            ...(array) config('infoprodutos.ips_equipe', []),
        ], $texto))));
    }

    /**
     * Rede da casa: o /64 do IPv6 (os primeiros 4 blocos, já normalizados)
     * ou o IPv4 inteiro. Null quando não é IP.
     */
    public static function rede(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        $binario = @inet_pton($ip);
        if ($binario === false) {
            return null;
        }
        if (strlen($binario) === 4) {
            return $ip;
        }

        return implode(':', array_map(fn ($bloco) => dechex(hexdec($bloco)), str_split(bin2hex(substr($binario, 0, 8)), 4))).'::/64';
    }

    /** @return list<int> */
    public static function usuariosDaEquipe(): array
    {
        return User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return list<string> navegadores (visitor_id) em que alguém da equipe entrou */
    public static function navegadoresDaEquipe(): array
    {
        return self::dados()['navegadores'];
    }

    /** @return list<string> redes (rede()) da equipe nos últimos dias */
    public static function redesDaEquipe(): array
    {
        return self::dados()['redes'];
    }

    public static function esquecer(): void
    {
        Cache::forget(self::CACHE);
    }

    /** @return array{navegadores: list<string>, redes: list<string>} */
    private static function dados(): array
    {
        return Cache::remember(self::CACHE, 600, function () {
            $usuarios = self::usuariosDaEquipe() ?: [0];
            $base = fn () => SiteVisit::query()->withoutGlobalScopes()->where('site', SiteVisit::SITE_LOJA);

            $navegadores = $base()->whereIn('user_id', $usuarios)->whereNotNull('visitor_id')
                ->where('visitor_id', '!=', 'anonimo')->distinct()->pluck('visitor_id')
                ->map(fn ($v) => (string) $v)->values()->all();

            $redes = $base()->where('created_at', '>=', now()->subDays(max(1, (int) config('visitas.dias_casa', 3))))
                ->whereNotNull('ip')
                ->where(fn ($q) => $q->whereIn('user_id', $usuarios)->orWhereIn('visitor_id', $navegadores ?: ['-']))
                ->distinct()->pluck('ip')
                ->map(fn ($ip) => self::rede((string) $ip))->filter()->unique()->values()->all();

            return ['navegadores' => $navegadores, 'redes' => $redes];
        });
    }
}
