<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Exceptions\MarketplaceNotConfiguredException;
use App\Modules\Marketplace\Models\MarketplaceAccount;

abstract class AbstractMarketplaceDriver implements MarketplaceChannelDriver
{
    public function isConfigured(): bool
    {
        return $this->account()?->isConnected() ?? false;
    }

    /**
     * Default: canal ainda sem auto-import implementado (só a Shopee tem,
     * por enquanto — ver ShopeeDriver::autoImportProduct()). Mesmo
     * comportamento de "sem produto vinculado" que já existia.
     */
    public function autoImportProduct(string $externalId, int $quantitySold = 0, ?string $externalModelId = null): ?Product
    {
        return null;
    }

    /**
     * Default: canal ainda sem busca de avaliações implementada (só a
     * Shopee tem, por enquanto — ver ShopeeDriver::fetchReviews()).
     * Lançar, e não devolver [], porque ReviewImportService precisa
     * distinguir "canal não suporta ainda" (pula o canal inteiro) de
     * "suporta mas esse produto não tem avaliação nenhuma" ([] real).
     */
    public function fetchReviews(string $externalId): array
    {
        throw new \RuntimeException("Importação de avaliações via {$this->channel()} ainda não implementada.");
    }

    protected function account(): ?MarketplaceAccount
    {
        return MarketplaceAccount::query()->where('channel', $this->channel())->first();
    }

    protected function ensureConfigured(): MarketplaceAccount
    {
        $account = $this->account();

        if (! $account?->isConnected()) {
            throw MarketplaceNotConfiguredException::forChannel($this->channel());
        }

        return $account;
    }
}
