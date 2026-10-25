<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Analytics\Models\VisitanteGeo;
use App\Support\Privacidade\Cookies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Latitude, longitude e CEP aproximados do visitante pelo IP (pedido
 * 2026-10-24), gravados por sessão em visitante_geolocalizacoes e na
 * própria sessão (chave SESSAO), para o frete pela localização usar depois.
 * Por enquanto só captura e grava.
 *
 * De onde vem, nesta ordem:
 * 1. Cabeçalhos da Cloudflare (regra "Add visitor location headers", a
 *    mesma que já preenche orders.visitante_latitude): CF-IPLatitude,
 *    CF-IPLongitude, CF-Postal-Code, CF-IPCity, CF-Region-Code,
 *    CF-IPCountry, CF-Timezone. Só valem quando a requisição chegou de um IP
 *    da Cloudflare (trustProxies): quem chama a VPS direto não forja o lugar.
 * 2. Base DB-IP no servidor (LocalDoIp): cidade, UF e o centro da cidade,
 *    sem CEP. Nada sai do servidor.
 *
 * É estimativa: IP de celular costuma cair na cidade da operadora, e o CEP
 * da Cloudflare às vezes vem só com os 5 primeiros dígitos (prefixo).
 */
class GeoDoVisitante
{
    /** Chave na sessão com o último lugar gravado. */
    public const SESSAO = 'visitante_geo';

    public function __construct(private readonly LocalDoIp $local) {}

    /**
     * Visitante da loja (middleware RegistrarGeoDoVisitante): grava uma vez
     * por sessão e de novo só se o IP ou o login mudarem.
     */
    public function registrarDaLoja(Request $request): ?VisitanteGeo
    {
        $sessao = 's-'.substr(hash('sha256', $request->session()->getId()), 0, 34);
        $userId = $request->user()?->id;
        $guardado = $request->session()->get(self::SESSAO);

        if (is_array($guardado) && ($guardado['sessao'] ?? null) === $sessao
            && ($guardado['ip'] ?? null) === $request->ip() && ($guardado['user_id'] ?? null) === $userId) {
            return null;
        }

        // Com o OK dos cookies, o visitante é o cookie (igual a site_visits).
        $visitorId = Cookies::aceitou($request) ? ($request->cookie(Cookies::COOKIE_VISITANTE) ?: $sessao) : Cookies::visitanteSemOk($request);

        $geo = $this->registrar($request, SiteVisit::SITE_LOJA, $sessao, $visitorId, $userId);

        $request->session()->put(self::SESSAO, [
            'sessao' => $sessao,
            'ip' => $request->ip(),
            'user_id' => $userId,
            'fonte' => $geo?->fonte,
            'uf' => $geo?->uf,
            'cidade' => $geo?->cidade,
            'cep' => $geo?->cep,
            'latitude' => $geo?->latitude !== null ? (float) $geo->latitude : null,
            'longitude' => $geo?->longitude !== null ? (float) $geo->longitude : null,
        ]);

        return $geo;
    }

    /** Grava (ou atualiza) a linha da sessão. Null = não deu para saber o lugar. */
    public function registrar(Request $request, string $site, string $sessao, ?string $visitorId = null, ?int $userId = null): ?VisitanteGeo
    {
        $lugar = $this->localizar($request);

        if ($lugar === null) {
            return null;
        }

        return VisitanteGeo::query()->updateOrCreate(
            ['site' => $site, 'sessao' => $sessao],
            ['visitor_id' => $visitorId, 'user_id' => $userId, 'ip' => $request->ip(), ...$lugar],
        );
    }

    /**
     * @return array{fonte: string, pais: ?string, uf: ?string, cidade: ?string, cep: ?string, latitude: ?float, longitude: ?float, fuso: ?string}|null
     */
    public function localizar(Request $request): ?array
    {
        return $this->daCloudflare($request) ?? $this->daBaseLocal((string) $request->ip());
    }

    private function daCloudflare(Request $request): ?array
    {
        $remoto = (string) $request->server->get('REMOTE_ADDR');
        $proxies = Request::getTrustedProxies();

        if ($remoto === '' || $proxies === [] || ! IpUtils::checkIp($remoto, $proxies)) {
            return null;
        }

        $lat = $this->coordenada($request->header('CF-IPLatitude'), 90);
        $lng = $this->coordenada($request->header('CF-IPLongitude'), 180);

        if ($lat === null || $lng === null) {
            return null;
        }

        $pais = $this->texto($request->header('CF-IPCountry'), 2);
        $pais = $pais !== null ? strtoupper($pais) : null;
        $uf = $this->texto($request->header('CF-Region-Code'), 2);

        return [
            'fonte' => VisitanteGeo::FONTE_CLOUDFLARE,
            'pais' => $pais,
            'uf' => $pais === 'BR' && $uf !== null && preg_match('/^[A-Za-z]{2}$/', $uf) ? strtoupper($uf) : null,
            'cidade' => $this->texto($request->header('CF-IPCity'), 120),
            'cep' => $pais === 'BR' ? self::cep($request->header('CF-Postal-Code')) : null,
            'latitude' => $lat,
            'longitude' => $lng,
            'fuso' => $this->texto($request->header('CF-Timezone'), 60),
        ];
    }

    private function daBaseLocal(string $ip): ?array
    {
        $lugar = $this->local->buscar($ip);

        if ($lugar === null || ($lugar['latitude'] ?? null) === null || ($lugar['longitude'] ?? null) === null) {
            return null;
        }

        return [
            'fonte' => VisitanteGeo::FONTE_DBIP,
            'pais' => $lugar['pais'] ?? null,
            'uf' => $lugar['uf'] ?? null,
            'cidade' => isset($lugar['cidade']) ? mb_substr($lugar['cidade'], 0, 120) : null,
            'cep' => null,
            'latitude' => (float) $lugar['latitude'],
            'longitude' => (float) $lugar['longitude'],
            'fuso' => null,
        ];
    }

    /** "01310-100", ou só o prefixo "01310" quando a Cloudflare manda 5 dígitos. */
    public static function cep(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        return match (strlen($digitos)) {
            8 => substr($digitos, 0, 5).'-'.substr($digitos, 5),
            5 => $digitos,
            default => null,
        };
    }

    private function coordenada(?string $valor, int $limite): ?float
    {
        if ($valor === null || ! is_numeric($valor)) {
            return null;
        }

        $numero = (float) $valor;

        return abs($numero) <= $limite ? round($numero, 7) : null;
    }

    private function texto(?string $valor, int $limite): ?string
    {
        $valor = trim(strip_tags((string) $valor));

        return $valor === '' ? null : mb_substr($valor, 0, $limite);
    }
}
