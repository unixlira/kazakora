<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Checkout\Models\Order;
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

    /**
     * Só envio/etiqueta (ChannelShippingService, LabelFetchService). Fica
     * fora de channels(): o site não publica produto nem importa pedido.
     *
     * @var array<string, class-string<MarketplaceChannelDriver>>
     */
    private array $somenteEnvio = [
        Order::ORIGIN_STORE => LojaShippingDriver::class,
    ];

    public function driver(string $channel): MarketplaceChannelDriver
    {
        $classe = $this->drivers[$channel] ?? $this->somenteEnvio[$channel] ?? null;

        if (! $classe) {
            throw new InvalidArgumentException("Canal de marketplace desconhecido: {$channel}");
        }

        return app($classe);
    }

    /**
     * @return array<int, string>
     */
    public function channels(): array
    {
        return array_keys($this->drivers);
    }
}
