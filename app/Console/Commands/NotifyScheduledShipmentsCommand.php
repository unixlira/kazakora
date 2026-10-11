<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Notifications\ScheduledShipmentsDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Pedido explícito 2026-08-14, depois do pedido #278 (venda de Coleta/
 * Places do Mercado Livre agendada pro dia 17 — o canal só libera a
 * etiqueta perto da data, não é um pedido travado de verdade, mas parecia
 * exatamente isso pra quem olhava o KoraSync sem saber). "Uma cron que
 * fica rodando pra ter acesso a isso e atualizar a gente" — resumo diário
 * (ver routes/console.php, dailyAt) com dois grupos:
 *
 * - "Próximas 48h": aviso tranquilo, é só pra saber que vem por aí.
 * - "Atrasadas": o canal já devia ter liberado (scheduled_for no passado)
 *   e não liberou — isso sim merece atenção de verdade, ver com o canal.
 *
 * Não marca "já notificado" em lugar nenhum — de propósito: um resumo
 * diário simples que repete até o pedido embalar é mais fácil de entender
 * pra todo mundo do time do que um controle de "já avisei essa uma vez"
 * escondido no banco. Silencioso (sem notificação nenhuma) quando não há
 * nada em nenhum dos dois grupos — não polui o sino do admin todo dia à
 * toa.
 */
class NotifyScheduledShipmentsCommand extends Command
{
    protected $signature = 'marketplace:notify-scheduled-shipments';

    protected $description = 'Avisa os admins sobre vendas agendadas pelo canal (Coleta/Places) que vão liberar etiqueta em breve, ou que já deveriam ter liberado e não liberaram';

    public function handle(): int
    {
        // BUG REAL 2026-08-14, corrigido no mesmo dia: filtrar por
        // order.packed_at aqui excluiria um envio já "embalado" no
        // KoraSync mesmo com o canal ainda sem liberar a etiqueta de
        // verdade — embalar é sobre a caixa estar pronta, não sobre a
        // etiqueta existir (ver mesmo comentário em
        // DashboardAgentController::scheduledShipments()).
        $shipments = ChannelShipment::query()
            ->whereNotNull('scheduled_for')
            ->whereNotIn('status', [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED])
            ->whereHas('order')
            ->orderBy('scheduled_for')
            ->get();

        $upcoming = $shipments->filter(fn (ChannelShipment $s) => $s->scheduled_for->isFuture() && $s->scheduled_for->diffInHours(now()) <= 48);
        $overdue = $shipments->filter(fn (ChannelShipment $s) => $s->scheduled_for->isPast());

        if ($upcoming->isEmpty() && $overdue->isEmpty()) {
            $this->info('Nenhuma venda agendada pendente hoje — nada a avisar.');

            return self::SUCCESS;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new ScheduledShipmentsDigestNotification($upcoming, $overdue));
        }

        $this->info("Avisado: {$upcoming->count()} próxima(s) 48h, {$overdue->count()} atrasada(s).");

        return self::SUCCESS;
    }
}
