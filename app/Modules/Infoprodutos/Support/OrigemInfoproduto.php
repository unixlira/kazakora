<?php

namespace App\Modules\Infoprodutos\Support;

use Illuminate\Http\Request;

/** A chamada veio de um dos sites medidos (config infoprodutos.origens)? */
class OrigemInfoproduto
{
    public static function host(Request $request): string
    {
        $url = (string) ($request->headers->get('origin') ?: $request->headers->get('referer'));

        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    public static function permitida(Request $request): bool
    {
        $host = self::host($request);

        return $host !== '' && in_array($host, self::dominios(), true);
    }

    /** @return list<string> */
    public static function dominios(): array
    {
        return array_values(array_map(fn ($d) => preg_replace('/^www\./', '', strtolower($d)), (array) config('infoprodutos.origens')));
    }
}
