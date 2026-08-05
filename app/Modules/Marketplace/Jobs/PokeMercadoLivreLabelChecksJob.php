<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado a cada webhook do Mercado Livre que chega (qualquer tópico —
 * MercadoLivreController::webhook()), como um "empurrão": redispara
 * CheckShipmentLabelJob pra qualquer envio já confirmado que ainda não tem
 * etiqueta. Barato e idempotente — CheckShipmentLabelJob é ShouldBeUnique
 * por shipment_id, então isso nunca empilha retries duplicados, só garante
 * que nenhum envio fica esquecido se o dispatch original
 * (ChannelShippingService::confirm()) não rodou por algum motivo.
 *
 * Fica na fila "default" de propósito (sem onQueue() customizado) — já é a
 * fila que o worker do homolog escuta de verdade. Ver o comentário em
 * config/mercadolivre.php sobre o bug real de fila-nomeada-mas-não-escutada
 * (2026-08-01) antes de "otimizar" isso pra uma fila dedicada sem também
 * atualizar o comando do cron do worker no servidor.
 */
class PokeMercadoLivreLabelChecksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->where('status', ChannelShipment::STATUS_CONFIRMED)
            ->pluck('id')
            ->each(fn (int $id) => CheckShipmentLabelJob::dispatch($id));
    }
}
