<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Logos e favicon da loja (pedido 2026-10-10): sobem em Admin > Banners >
 * "Logos e favicon". Sem upload, vale o padrão em public/images/marca.
 */
class Marca
{
    public const ITENS = [
        'logo_nav' => ['padrao' => '/images/marca/logo-nav.png'],
        'logo_rodape' => ['padrao' => '/images/marca/logo-rodape.png'],
        'favicon' => ['padrao' => '/images/marca/favicon-32.png'],
    ];

    public static function chave(string $item): string
    {
        return 'marca.'.$item;
    }

    public static function caminho(string $item): ?string
    {
        return Setting::get(self::chave($item));
    }

    public static function url(string $item): string
    {
        $caminho = self::caminho($item);

        return $caminho ? Storage::disk('public')->url($caminho) : self::ITENS[$item]['padrao'];
    }

    /** @return array{logoNav: string, logoRodape: string, favicon: string, faviconTipo: string} */
    public static function urls(): array
    {
        $favicon = self::url('favicon');

        return [
            'logoNav' => self::url('logo_nav'),
            'logoRodape' => self::url('logo_rodape'),
            'favicon' => $favicon,
            'faviconTipo' => str_ends_with(strtolower(parse_url($favicon, PHP_URL_PATH) ?? ''), '.ico') ? 'image/x-icon' : 'image/png',
        ];
    }
}
