<?php

namespace App\Modules\Checkout\Jobs;

use App\Models\User;
use App\Modules\Admin\Models\PromotionalNotificationCampaign;
use App\Modules\Checkout\Mail\CupomPromocional;
use App\Modules\Checkout\Models\CouponDisparo;
use App\Modules\Checkout\Support\PublicoCupom;
use App\Notifications\PromotionalNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Um lote (até 50 clientes) de um disparo de cupom. Sem retry: e-mail que
 * já saiu não pode sair de novo; falha vira contagem em "falhas".
 */
class EnviarCupomLoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /** @param list<int> $userIds */
    public function __construct(public readonly int $disparoId, public readonly array $userIds, public readonly ?int $campanhaSiteId = null)
    {
    }

    public function handle(): void
    {
        $disparo = CouponDisparo::query()->with('coupon')->find($this->disparoId);
        if (! $disparo) {
            return;
        }

        $users = User::query()->whereIn('id', $this->userIds)->get();
        $campanhaSite = $this->campanhaSiteId ? PromotionalNotificationCampaign::find($this->campanhaSiteId) : null;
        $enviados = 0;
        $falhas = 0;

        foreach ($users as $user) {
            try {
                if (in_array('email', $disparo->canais, true)) {
                    Mail::to($user->email)->send(new CupomPromocional(
                        $user,
                        $disparo->coupon,
                        PublicoCupom::preencher($disparo->assunto, $disparo->coupon, $user),
                        PublicoCupom::preencher($disparo->mensagem, $disparo->coupon, $user),
                    ));
                }
                if ($campanhaSite) {
                    $user->notify(new PromotionalNotification($campanhaSite));
                }
                $enviados++;
            } catch (Throwable $exception) {
                $falhas++;
                Log::warning('cupom_disparo_falhou', ['disparo_id' => $disparo->id, 'user_id' => $user->id, 'error' => $exception->getMessage()]);
            }
        }

        $disparo->increment('enviados', $enviados);
        $disparo->increment('falhas', $falhas);

        $disparo->refresh();
        if ($disparo->enviados + $disparo->falhas >= $disparo->total && $disparo->status !== CouponDisparo::STATUS_CONCLUIDO) {
            $disparo->update(['status' => CouponDisparo::STATUS_CONCLUIDO, 'concluido_em' => now()]);
        }
    }
}
