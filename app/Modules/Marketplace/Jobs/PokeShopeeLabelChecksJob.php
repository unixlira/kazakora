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
 * Mesmo padrão de PokeMercadoLivreLabelChecksJob, pra Shopee — disparado a
 * cada webhook que a Shopee manda (ProcessShopeeWebhook::handle()), como um
 * "empurrão": redispara CheckShipmentLabelJob pra qualquer envio Shopee que
 * ainda não tem etiqueta, em vez de depender só do backoff passivo do job
 * (5s "de direito", mas na prática só roda quando o cron do host chama
 * `queue:work --stop-when-empty`, ~1x/min — sem worker persistente aqui,
 * ver comentário em CheckShipmentLabelJob). Reclamação real do usuário
 * 2026-08-12 ("porra proximo ciclo, isso tem que funcionar em segundos") —
 * a Shopee já manda webhook quase toda vez que o status logístico muda,
 * então esse empurrão aproxima o tempo de reação de "segundos depois do
 * evento real" em vez de "até 1min de espera cega".
 *
 * Inclui qualquer status que ainda não tem etiqueta — não só CONFIRMED como
 * a versão do ML, porque o pedido #248 (2026-08-12) mostrou na prática que
 * ChannelShipment pode ficar em STATUS_ERROR mesmo com o envio já
 * genuinamente arranjado do lado da Shopee (corrida entre dois submits de
 * nota concorrentes) — LabelFetchService::attempt() não depende do status
 * do shipment pra funcionar, só CheckShipmentLabelJob/alreadyHasLabel()
 * decide parar, então empurrar mesmo um shipment "error" é seguro e pode
 * ser exatamente o que destrava.
 *
 * Fica na fila "default" de propósito, mesmo motivo do job do ML — é a
 * fila que o worker do homolog realmente escuta.
 */
class PokeShopeeLabelChecksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->whereNotIn('status', [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED])
            ->pluck('id')
            ->each(fn (int $id) => CheckShipmentLabelJob::dispatch($id));
    }
}
