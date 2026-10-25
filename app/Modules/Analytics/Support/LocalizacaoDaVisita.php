<?php

namespace App\Modules\Analytics\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Latitude, longitude e CEP aproximados de cada visita (pedido 2026-10-24:
 * o Lira quer ver no olho das Visitas o que foi capturado do IP, para
 * validar o rastreio).
 *
 * Vêm da Cloudflare (regra "Add visitor location headers", a mesma dos
 * pedidos: CF-IPLatitude, CF-IPLongitude e CF-Postal-Code), gravados em
 * site_visits na hora da visita. Sem Cloudflare (homolog, acesso direto)
 * fica nulo. Visitas de antes de 24/10/2026 não têm. Para essas, o card
 * mostra a estimativa da base DB-IP do servidor (LocalDoIp), sem CEP.
 *
 * É a localização do IP, não do endereço: celular costuma cair na cidade
 * da operadora. Some junto com o IP depois de 6 meses (privacidade:limpar).
 */
class LocalizacaoDaVisita
{
    /** Localizações diferentes listadas no card (o IP muda pouco de lugar). */
    public const MAX_LOCALIZACOES = 5;

    /** @return array{latitude: ?float, longitude: ?float, cep: ?string} */
    public static function doRequest(Request $request): array
    {
        $lat = $request->header('CF-IPLatitude');
        $lng = $request->header('CF-IPLongitude');
        $valida = is_numeric($lat) && is_numeric($lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;

        // A Cloudflare manda o CEP com ou sem hífen, às vezes só o começo (5 números).
        $cep = preg_replace('/\D/', '', (string) $request->header('CF-Postal-Code'));

        return [
            'latitude' => $valida ? round((float) $lat, 7) : null,
            'longitude' => $valida ? round((float) $lng, 7) : null,
            'cep' => $cep !== '' && strlen($cep) <= 8 ? $cep : null,
        ];
    }

    /**
     * O card do olho: as localizações gravadas nas visitas da consulta (a
     * mais recente primeiro, com quantas visitas e quando foi a última) e a
     * estimativa da base do servidor para o IP.
     *
     * @return array{capturas: list<array{latitude: float, longitude: float, cep: ?string, visitas: int, ultima: ?string}>, com_localizacao: int, base: ?array}
     */
    public static function resumo(Builder $query, ?string $ip, LocalDoIp $local): array
    {
        $comLocalizacao = (clone $query)->whereNotNull('latitude')->whereNotNull('longitude');

        $capturas = (clone $comLocalizacao)
            ->reorder()
            ->selectRaw('latitude, longitude, cep, count(*) as visitas, max(created_at) as ultima')
            ->groupBy('latitude', 'longitude', 'cep')
            ->orderByDesc('ultima')
            ->limit(self::MAX_LOCALIZACOES)
            ->toBase()
            ->get()
            ->map(fn (object $l) => [
                'latitude' => (float) $l->latitude,
                'longitude' => (float) $l->longitude,
                'cep' => $l->cep,
                'visitas' => (int) $l->visitas,
                'ultima' => $l->ultima ? Carbon::parse($l->ultima)->format('d/m/Y H:i:s') : null,
            ])
            ->values()
            ->all();

        $lugar = $ip !== null ? $local->buscar($ip) : null;

        return [
            'capturas' => $capturas,
            'com_localizacao' => $capturas === [] ? 0 : $comLocalizacao->count(),
            'base' => isset($lugar['latitude'], $lugar['longitude']) ? [
                'latitude' => $lugar['latitude'],
                'longitude' => $lugar['longitude'],
                'cidade' => $lugar['cidade'] ?? null,
                'uf' => $lugar['uf'] ?? null,
                'pais' => $lugar['pais'] ?? null,
            ] : null,
        ];
    }
}
