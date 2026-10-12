<?php

namespace App\Modules\Analytics\Support;

/**
 * De onde o visitante veio (pedido 2026-10-12, tela Visitas ao site).
 * Lugar único da regra: o TrackSiteVisit grava a chave em site_visits.origem
 * na hora da visita e a tela mostra o rótulo daqui.
 *
 * Ordem (o que foi marcado vale mais que o site de onde veio):
 *  1. gclid/gbraid/wbraid/dclid: Google Ads (só o Google põe).
 *  2. utm_source conhecido (com utm_medium pago vira "Ads" quando existe).
 *  3. Outros identificadores de clique (fbclid, ttclid, msclkid...).
 *  4. Site de onde veio (Referer). Do próprio site = Interno.
 *  5. Nada disso = Direto (digitou, favorito, app que não manda Referer).
 *
 * Visita gravada antes disso não tem chave (null) e aparece como "Sem dado".
 */
class OrigemVisita
{
    public const SEM_DADO = 'sem_dado';

    /** Parâmetros de clique de anúncio/rede, na ordem de busca na URL. */
    public const CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'ttclid', 'msclkid', 'twclid', 'li_fat_id', 'epik', 'igshid', 'yclid', 'sccid'];

    public const ROTULOS = [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'meta' => 'Instagram/Facebook',
        'meta_ads' => 'Meta Ads',
        'google' => 'Google',
        'google_ads' => 'Google Ads',
        'tiktok' => 'TikTok',
        'tiktok_ads' => 'TikTok Ads',
        'whatsapp' => 'WhatsApp',
        'shopee' => 'Shopee',
        'mercado_livre' => 'Mercado Livre',
        'mercado_pago' => 'Mercado Pago',
        'youtube' => 'YouTube',
        'bing' => 'Bing',
        'bing_ads' => 'Bing Ads',
        'x' => 'X (Twitter)',
        'pinterest' => 'Pinterest',
        'linkedin' => 'LinkedIn',
        'kwai' => 'Kwai',
        'telegram' => 'Telegram',
        'email' => 'E-mail',
        'campanha' => 'Campanha',
        'outro' => 'Outro site',
        'direto' => 'Direto',
        'interno' => 'Interno',
        self::SEM_DADO => 'Sem dado',
    ];

    /** utm_source (minúsculo) → chave. */
    private const UTM_SOURCES = [
        'instagram' => 'instagram', 'ig' => 'instagram', 'insta' => 'instagram',
        'facebook' => 'facebook', 'fb' => 'facebook', 'messenger' => 'facebook',
        'meta' => 'meta', 'metaads' => 'meta_ads', 'meta_ads' => 'meta_ads', 'facebook_ads' => 'meta_ads', 'fb_ads' => 'meta_ads',
        'google' => 'google', 'googleads' => 'google_ads', 'google_ads' => 'google_ads', 'adwords' => 'google_ads',
        'tiktok' => 'tiktok', 'tiktokads' => 'tiktok_ads', 'tiktok_ads' => 'tiktok_ads',
        'whatsapp' => 'whatsapp', 'wa' => 'whatsapp', 'zap' => 'whatsapp', 'wpp' => 'whatsapp',
        'shopee' => 'shopee',
        'mercadolivre' => 'mercado_livre', 'mercado_livre' => 'mercado_livre', 'mercadolibre' => 'mercado_livre', 'ml' => 'mercado_livre', 'meli' => 'mercado_livre',
        'youtube' => 'youtube', 'yt' => 'youtube',
        'bing' => 'bing', 'microsoft' => 'bing',
        'twitter' => 'x', 'x' => 'x',
        'pinterest' => 'pinterest',
        'linkedin' => 'linkedin',
        'kwai' => 'kwai',
        'telegram' => 'telegram',
        'email' => 'email', 'e-mail' => 'email', 'newsletter' => 'email', 'gmail' => 'email',
    ];

    /** Fonte que tem versão "Ads" quando o utm_medium é pago. */
    private const VERSAO_ADS = [
        'google' => 'google_ads', 'facebook' => 'meta_ads', 'instagram' => 'meta_ads', 'meta' => 'meta_ads',
        'tiktok' => 'tiktok_ads', 'bing' => 'bing_ads',
    ];

    private const MEDIUM_PAGO = '/^(cpc|ppc|cpm|cpv|paid|paid[_-]?social|paidsocial|ads?|display|banner|retargeting|remarketing)$/';

    /** Domínio (sem www, termina com) → chave. Ordem importa: o mais específico antes. */
    private const DOMINIOS = [
        'googleadservices.com' => 'google_ads', 'googlesyndication.com' => 'google_ads', 'doubleclick.net' => 'google_ads',
        'mail.google.com' => 'email',
        'instagram.com' => 'instagram',
        'facebook.com' => 'facebook', 'fb.com' => 'facebook', 'fb.me' => 'facebook', 'messenger.com' => 'facebook',
        'tiktok.com' => 'tiktok',
        'wa.me' => 'whatsapp', 'whatsapp.com' => 'whatsapp',
        'shp.ee' => 'shopee',
        'mercadolivre.com.br' => 'mercado_livre', 'mercadolivre.com' => 'mercado_livre', 'mercadolibre.com' => 'mercado_livre', 'meli.la' => 'mercado_livre',
        'mercadopago.com.br' => 'mercado_pago', 'mercadopago.com' => 'mercado_pago',
        'youtube.com' => 'youtube', 'youtu.be' => 'youtube',
        'bing.com' => 'bing',
        't.co' => 'x', 'x.com' => 'x', 'twitter.com' => 'x',
        'pin.it' => 'pinterest',
        'linkedin.com' => 'linkedin', 'lnkd.in' => 'linkedin',
        'kwai.com' => 'kwai',
        't.me' => 'telegram', 'telegram.org' => 'telegram', 'telegram.me' => 'telegram',
        'outlook.live.com' => 'email', 'outlook.office.com' => 'email', 'mail.yahoo.com' => 'email',
    ];

    /** Referer de app Android (android-app://pacote/). */
    private const APPS_ANDROID = [
        'com.instagram.android' => 'instagram',
        'com.facebook.katana' => 'facebook', 'com.facebook.lite' => 'facebook', 'com.facebook.orca' => 'facebook',
        'com.whatsapp' => 'whatsapp', 'com.whatsapp.w4b' => 'whatsapp',
        'com.zhiliaoapp.musically' => 'tiktok', 'com.ss.android.ugc.trill' => 'tiktok',
        'com.google.android.googlequicksearchbox' => 'google', 'com.google.android.gm' => 'email',
        'com.google.android.youtube' => 'youtube',
        'com.shopee.br' => 'shopee', 'com.mercadolibre' => 'mercado_livre',
        'com.linkedin.android' => 'linkedin', 'com.twitter.android' => 'x', 'com.pinterest' => 'pinterest',
    ];

    /** Domínios que são o próprio site (produção e homolog). */
    private const DOMINIOS_PROPRIOS = ['kazakora.com', 'kazakora.devlira.com.br'];

    /**
     * Chave da origem de uma visita.
     *
     * @param  array{utm_source?: ?string, utm_medium?: ?string, utm_campaign?: ?string}  $utm
     * @param  ?string  $clickParam  nome do parâmetro de clique achado na URL (fbclid, gclid...)
     */
    public static function classificar(?string $referer, array $utm = [], ?string $clickParam = null): string
    {
        $clickParam = $clickParam !== null ? strtolower($clickParam) : null;
        $porReferer = self::pelaUrl($referer);

        if (in_array($clickParam, ['gclid', 'gbraid', 'wbraid', 'dclid'], true)) {
            return 'google_ads';
        }

        $source = self::normalizar($utm['utm_source'] ?? null);
        if ($source !== null) {
            $chave = self::UTM_SOURCES[$source] ?? self::pelaUrl('https://'.$source) ?? 'campanha';
            if ($chave === 'outro' || $chave === 'interno') {
                $chave = 'campanha';
            }
            $medium = self::normalizar($utm['utm_medium'] ?? null);
            if ($medium !== null && isset(self::VERSAO_ADS[$chave]) && preg_match(self::MEDIUM_PAGO, $medium) === 1) {
                return self::VERSAO_ADS[$chave];
            }

            return $chave;
        }

        $porClique = match ($clickParam) {
            // fbclid vem do Facebook e do Instagram: o Referer desempata.
            'fbclid' => in_array($porReferer, ['instagram', 'facebook'], true) ? $porReferer : 'meta',
            'igshid' => 'instagram',
            'ttclid' => 'tiktok_ads',
            'msclkid' => 'bing_ads',
            'twclid' => 'x',
            'li_fat_id' => 'linkedin',
            'epik' => 'pinterest',
            default => null,
        };

        return $porClique ?? $porReferer ?? 'direto';
    }

    /** Rótulo para a tela. "Outro site" leva o domínio; campanha desconhecida leva o utm_source. */
    public static function rotulo(?string $chave, ?string $referer = null, ?string $utmSource = null): string
    {
        $chave ??= self::SEM_DADO;

        if ($chave === 'outro' && ($host = self::host($referer)) !== null) {
            return 'Outro site ('.$host.')';
        }

        if ($chave === 'campanha' && $utmSource !== null && trim($utmSource) !== '') {
            return 'Campanha ('.trim($utmSource).')';
        }

        return self::ROTULOS[$chave] ?? 'Outro site';
    }

    /**
     * Primeiro identificador de clique da URL: [parâmetro, valor].
     *
     * @param  array<string, mixed>  $query
     * @return array{0: ?string, 1: ?string}
     */
    public static function clickId(array $query): array
    {
        $porNome = [];
        foreach ($query as $nome => $valor) {
            $porNome[strtolower((string) $nome)] ??= $valor;
        }

        foreach (self::CLICK_IDS as $param) {
            $valor = $porNome[$param] ?? null;
            if (is_string($valor) && trim($valor) !== '') {
                return [$param, mb_substr(trim(strip_tags($valor)), 0, 255)];
            }
        }

        return [null, null];
    }

    /** Domínio sem www, ou null. */
    public static function host(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    /** Chave pelo Referer (ou null sem Referer). */
    private static function pelaUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if (str_starts_with(strtolower($url), 'android-app://')) {
            $pacote = strtolower((string) parse_url($url, PHP_URL_HOST));
            foreach (self::APPS_ANDROID as $prefixo => $chave) {
                if ($pacote === $prefixo || str_starts_with($pacote, $prefixo.'.')) {
                    return $chave;
                }
            }

            return 'outro';
        }

        // Referer sem "https://" (gravado assim antes de out/2026) ou lixo de
        // robô: veio algo, então não é Direto.
        $host = self::host($url) ?? self::host('https://'.ltrim(trim($url), '/'));
        if ($host === null || ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            return 'outro';
        }

        foreach (self::dominiosProprios() as $proprio) {
            if (self::termina($host, $proprio)) {
                return 'interno';
            }
        }

        foreach (self::DOMINIOS as $dominio => $chave) {
            if (self::termina($host, $dominio)) {
                return $chave;
            }
        }

        // google.com, google.com.br, google.pt...; shopee.com.br, shopee.co...
        return match (true) {
            preg_match('/(^|\.)google\.[a-z.]+$/', $host) === 1 => 'google',
            preg_match('/(^|\.)shopee\.[a-z.]+$/', $host) === 1 => 'shopee',
            preg_match('/(^|\.)pinterest\.[a-z.]+$/', $host) === 1 => 'pinterest',
            default => 'outro',
        };
    }

    /** @return array<int, string> */
    private static function dominiosProprios(): array
    {
        $doApp = self::host((string) config('app.url'));

        return array_values(array_unique(array_filter([...self::DOMINIOS_PROPRIOS, $doApp])));
    }

    private static function termina(string $host, string $dominio): bool
    {
        return $host === $dominio || str_ends_with($host, '.'.$dominio);
    }

    private static function normalizar(?string $valor): ?string
    {
        $valor = $valor !== null ? strtolower(trim($valor)) : '';

        return $valor !== '' ? $valor : null;
    }
}
