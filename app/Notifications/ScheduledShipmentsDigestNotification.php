<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resumo diário de vendas AGENDADAS pelo canal (pedido explícito
 * 2026-08-14, achado no pedido #278 — Coleta/Places do Mercado Livre com
 * etiqueta liberada só perto de uma data futura). Espelha
 * LabelUnavailableNotification (mesmo canal 'database', mesma ideia de
 * aparecer no sino do admin) — a diferença é que este é um resumo
 * recorrente (roda 1x/dia, ver NotifyScheduledShipmentsCommand), não um
 * evento único de falha.
 */
class ScheduledShipmentsDigestNotification extends Notification
{
    /**
     * @param  Collection<int, \App\Modules\Marketplace\Models\ChannelShipment>  $upcoming
     * @param  Collection<int, \App\Modules\Marketplace\Models\ChannelShipment>  $overdue
     */
    public function __construct(
        private readonly Collection $upcoming,
        private readonly Collection $overdue,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'upcoming_count' => $this->upcoming->count(),
            'overdue_count' => $this->overdue->count(),
            'upcoming_orders' => $this->upcoming->pluck('order_id')->all(),
            'overdue_orders' => $this->overdue->pluck('order_id')->all(),
            'message' => $this->buildMessage(),
        ];
    }

    private function buildMessage(): string
    {
        $parts = [];

        if ($this->upcoming->isNotEmpty()) {
            $orders = $this->upcoming->map(fn ($s) => "#{$s->order_id}")->implode(', ');
            $parts[] = "{$this->upcoming->count()} venda(s) agendada(s) pra liberar etiqueta nas próximas 48h: {$orders}.";
        }

        if ($this->overdue->isNotEmpty()) {
            $orders = $this->overdue->map(fn ($s) => "#{$s->order_id}")->implode(', ');
            $parts[] = "ATENÇÃO: {$this->overdue->count()} venda(s) já passaram da data que o canal prometeu e ainda não liberaram etiqueta: {$orders}.";
        }

        return implode(' ', $parts);
    }
}
