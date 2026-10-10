<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Admin\Models\PromotionalNotificationCampaign;
use App\Modules\Checkout\Models\CouponDisparo;
use App\Modules\Checkout\Support\PublicoCupom;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Disparo de cupom em lote (pedido 2026-10-10): monta o público e divide em
 * lotes de 50 (EnviarCupomLoteJob) — um job só com milhares de e-mails
 * estouraria o tempo do worker da hospedagem.
 */
class DispararCupomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $disparoId)
    {
    }

    public function handle(): void
    {
        $disparo = CouponDisparo::query()->with('coupon')->find($this->disparoId);
        if (! $disparo || $disparo->status !== CouponDisparo::STATUS_PENDENTE) {
            return;
        }

        $publico = PublicoCupom::query($disparo->publico, $disparo->dias ?? 30);
        $total = (clone $publico)->count();

        $campanhaSite = null;
        if (in_array('site', $disparo->canais, true) && $total > 0) {
            // Mesmo histórico das Notificações Promocionais (sineta da loja).
            $campanhaSite = PromotionalNotificationCampaign::create([
                'title' => PublicoCupom::preencher($disparo->assunto, $disparo->coupon),
                'message' => mb_strimwidth(PublicoCupom::preencher($disparo->mensagem, $disparo->coupon), 0, 500, '…'),
                'link' => '/?cupom='.$disparo->coupon->code,
                'created_by' => $disparo->created_by,
                'recipients_count' => $total,
                'sent_at' => now(),
            ]);
        }

        $disparo->update([
            'total' => $total,
            'status' => $total > 0 ? CouponDisparo::STATUS_ENVIANDO : CouponDisparo::STATUS_CONCLUIDO,
            'concluido_em' => $total > 0 ? null : now(),
        ]);

        $publico->select('users.id')->chunkById(50, function ($users) use ($disparo, $campanhaSite) {
            EnviarCupomLoteJob::dispatch($disparo->id, $users->pluck('id')->all(), $campanhaSite?->id);
        }, 'users.id', 'id');
    }
}
