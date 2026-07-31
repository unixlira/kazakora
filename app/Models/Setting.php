<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key-value store genérico pra configurações que precisam ser editáveis
 * pelo admin em runtime (não são segredo, não vivem em .env). Primeiro uso:
 * qual gateway de pagamento está ativo (Stripe ou Mercado Pago).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    private const CACHE_TTL_SECONDS = 60;

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember("setting.{$key}", self::CACHE_TTL_SECONDS, function () use ($key, $default) {
            return static::query()->where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting.{$key}");
    }
}
