<?php

namespace App\Modules\Editorial\Support;

use Illuminate\Support\Str;

class EditorialSafety
{
    public static function media(?string $url, string $kind): bool
    {
        if (! $url) {
            return true;
        }
        $decoded = $url;
        for ($i = 0; $i < 5; $i++) {
            $decoded = rawurldecode($decoded);
        }
        if (preg_match('/[\x00-\x20\\\\<>"\x27]/u', $decoded) || str_contains($decoded, '..') || str_contains($decoded, '%')) {
            return false;
        }
        $parts = parse_url($decoded);
        if ($parts === false || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        if (isset($parts['host'])) {
            if (($parts['scheme'] ?? '') !== 'https' || $parts['host'] !== parse_url(config('app.url'), PHP_URL_HOST)) {
                return false;
            }
        } elseif (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//') || isset($parts['scheme'])) {
            return false;
        }
        $path = $parts['path'] ?? '';

        return str_starts_with($path, '/storage/editorial/') && (bool) preg_match($kind === 'video' ? '/\.(mp4|webm)$/i' : '/\.(jpg|jpeg|png|webp)$/i', $path);
    }

    public static function comment(string $body): ?string
    {
        $body = class_exists(\Normalizer::class) ? \Normalizer::normalize($body, \Normalizer::FORM_KC) : $body;
        $body = trim(preg_replace('/[\p{Cf}\p{Cc}]/u', '', $body));
        $scan = Str::lower(Str::ascii(strtr($body, ['о' => 'o', 'а' => 'a', 'е' => 'e', 'і' => 'i', 'с' => 'c'])));
        $scan = strtr($scan, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']);
        $compact = preg_replace('/[^a-z0-9.:\/]/', '', $scan);
        if (preg_match('/[<>]|&(?:lt|gt|#)/i', $body) || preg_match('/https?|www|javascript|\.(com|net|org|br|io|test)\b|(?:dot|ponto)(?:com|net|org|br)/', $compact) || preg_match('/porra|caralho|merda|puta|foder|foda|vagabund|fdp|buceta|fuck|shit/', $compact)) {
            return null;
        }

        return $body;
    }
}
