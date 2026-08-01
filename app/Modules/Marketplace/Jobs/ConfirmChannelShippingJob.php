<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ChannelShippingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ConfirmChannelShippingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(ChannelShippingService $service): void
    {
        $order = Order::findOrFail($this->orderId);

        $service->confirm($order);
    }
}
