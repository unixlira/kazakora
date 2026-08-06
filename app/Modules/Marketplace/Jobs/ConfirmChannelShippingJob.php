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

    // Na Shopee (confirmado 2026-08-06 pelo usuário, que opera a loja de
    // verdade lá), ship_order só aceita depois que a nota fiscal já foi
    // enviada pro canal (upload_invoice_doc) — a Shopee gera o código de
    // rastreio só depois disso, e a etiqueta só sai depois do rastreio.
    // Esse job dispara em paralelo com GenerateInvoiceJob (não espera por
    // ele, ver ChannelShippingService::confirm()), então precisa de folga
    // suficiente pra sobreviver ao pipeline inteiro da nota (SEFAZ +
    // upload pro canal, cada etapa com seu próprio retry/backoff) antes de
    // desistir de verdade. 3 tentativas (~21min) era curto demais pra
    // isso — ampliado pra ~3h de janela total.
    public int $tries = 6;

    public array $backoff = [60, 300, 900, 1800, 3600, 7200];

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(ChannelShippingService $service): void
    {
        $order = Order::findOrFail($this->orderId);

        $service->confirm($order);
    }
}
