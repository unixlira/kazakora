<?php

namespace App\Modules\Marketplace\Jobs;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Exceptions\ChannelOrderNotFoundException;
use App\Modules\Marketplace\Exceptions\MarketplaceNotConfiguredException;
use App\Modules\Marketplace\Support\ChannelShippingService;
use App\Notifications\LabelUnavailableNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

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

        try {
            $service->confirm($order);
        } catch (MarketplaceNotConfiguredException $exception) {
            // Canal não conectado não vira conectado por retentativa: são
            // 6 tentativas por hora, por pedido, pra um erro que só uma
            // PESSOA resolve (conectar a conta na tela de integrações).
            $this->fail($exception);
        } catch (ChannelOrderNotFoundException $exception) {
            // UMA tentativa, e para. O canal não conhece o pedido e não vai
            // passar a conhecer com o tempo — as 6 tentativas com backoff de
            // até 2h viram só disputa de worker com nota fiscal e etiqueta
            // de venda de verdade. Foi assim que 110 pedidos do TikTok de
            // agosto geraram 7.794 falhas em 24h (incidente 2026-09-10).
            $this->fail($exception);
        }
    }

    /**
     * ChannelShippingService::confirm() já marca o ChannelShipment como
     * erro a cada tentativa (ver seu próprio catch) — isso só fecha o
     * buraco de visibilidade: sem esse método, esgotar as ~3h de retry sem
     * sucesso (ex: nota fiscal nunca sai por falta de dado, ver
     * ShopeeDriver::confirmShipping()) ficava só registrado em
     * failed_jobs, sem avisar ninguém — achado real 2026-08-08 revisando
     * uma fila de dezenas de falhas silenciosas acumuladas. Reaproveita
     * LabelUnavailableNotification (mesma ideia: envio/etiqueta que nunca
     * saiu do canal) em vez de criar uma notificação nova só pra isso.
     */
    public function failed(?Throwable $exception): void
    {
        $order = Order::find($this->orderId);

        if (! $order) {
            return;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new LabelUnavailableNotification($order, $exception?->getMessage() ?? 'Erro desconhecido ao confirmar o envio no canal.'));
        }
    }
}
