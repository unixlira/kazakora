<?php

namespace App\Modules\Admin\Jobs;

use App\Models\User;
use App\Modules\Admin\Models\PromotionalNotificationCampaign;
use App\Notifications\PromotionalNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Disparo em fila da notificação promocional pro cliente (pedido explícito
 * 2026-08-06: "disparo por fila") — nunca síncrono no request do admin, pra
 * não travar a tela de cadastro esperando o envio de verdade. Só vai pra
 * User::ROLE_CUSTOMER (staff — admin/manager/subscriber — fica de fora,
 * "não aparece para admins", pedido explícito). chunkById evita carregar
 * todos os clientes de uma vez na memória se a base crescer.
 */
class SendPromotionalNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $campaignId)
    {
    }

    public function handle(): void
    {
        $campaign = PromotionalNotificationCampaign::findOrFail($this->campaignId);

        $sentCount = 0;

        User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->chunkById(200, function ($customers) use ($campaign, &$sentCount) {
                Notification::send($customers, new PromotionalNotification($campaign));
                $sentCount += $customers->count();
            });

        $campaign->update([
            'recipients_count' => $sentCount,
            'sent_at' => now(),
        ]);
    }
}
