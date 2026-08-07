<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use InvalidArgumentException;

class MarketplaceDriverManager
{
    /**
     * @var array<string, class-string<MarketplaceChannelDriver>>
     */
    private array $drivers = [
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => MercadoLivreDriver::class,
        MarketplaceAccount::CHANNEL_SHOPEE => ShopeeDriver::class,
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => TikTokShopDriver::class,
        MarketplaceAccount::CHANNEL_AMAZON => AmazonDriver::class,
    ];

    public function driver(string $channel): MarketplaceChannelDriver
    {
        if (! isset($this->drivers[$channel])) {
            throw new InvalidArgumentException("Canal de marketplace desconhecido: {$channel}");
        }

        return app($this->drivers[$channel]);
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return array_keys($this->drivers);
    }
}
