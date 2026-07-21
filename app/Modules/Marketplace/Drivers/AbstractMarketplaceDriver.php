<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Marketplace\Exceptions\MarketplaceNotConfiguredException;
use App\Modules\Marketplace\Models\MarketplaceAccount;

abstract class AbstractMarketplaceDriver implements MarketplaceChannelDriver
{
    public function isConfigured(): bool
    {
        return $this->account()?->isConnected() ?? false;
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
