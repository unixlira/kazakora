<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Pixel da Meta do site (pedido do Lira 2026-10-16): o ID é editado pelo
 * admin em /admin/pixel-meta e guardado via Setting. Sem ID salvo no admin,
 * vale o META_ADS_PIXEL_ID do .env (como era antes da tela).
 * Usado pela loja (HandleInertiaRequests → Shared/pixelMeta.js) e pelas
 * campanhas de Vendas do Meta ADS (MetaAdsService).
 */
class PixelMeta
{
    private const SETTING_KEY = 'meta_pixel_id';

    public static function id(): ?string
    {
        $id = self::doAdmin() ?? config('services.meta_ads.pixel_id');

        return filled($id) ? (string) $id : null;
    }

    /** 'admin', 'env' ou null (sem pixel). */
    public static function origem(): ?string
    {
        if (self::doAdmin() !== null) {
            return 'admin';
        }

        return filled(config('services.meta_ads.pixel_id')) ? 'env' : null;
    }

    public static function doEnv(): ?string
    {
        $id = config('services.meta_ads.pixel_id');

        return filled($id) ? (string) $id : null;
    }

    /** null apaga o ID do admin e volta a valer o do .env. */
    public static function definir(?string $id): void
    {
        Setting::set(self::SETTING_KEY, filled($id) ? $id : null);
    }

    private static function doAdmin(): ?string
    {
        $id = Setting::get(self::SETTING_KEY);

        return filled($id) ? $id : null;
    }
}
