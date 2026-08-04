<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ChannelInvoiceSubmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SubmitInvoiceToChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /** Mesma fila isolada da nota fiscal — ver GenerateInvoiceJob::$queue. */
    public string $queue = 'nfe';

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(ChannelInvoiceSubmissionService $service): void
    {
        $order = Order::findOrFail($this->orderId);

        $service->submit($order);
    }
}
