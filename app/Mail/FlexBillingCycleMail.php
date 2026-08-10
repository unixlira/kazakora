<?php

namespace App\Mail;

use App\Modules\Marketplace\Models\FlexBillingPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Fechamento de ciclo do Mercado Envios Flex — pedido explícito
 * 2026-08-10, dia 15 e fim do mês, avisando quantas entregas Flex e
 * quanto tem que pagar no período.
 */
class FlexBillingCycleMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly FlexBillingPeriod $period)
    {
    }

    public function build(): self
    {
        return $this
            ->subject(sprintf(
                'Fechamento Flex %s a %s — %s',
                $this->period->period_start->format('d/m'),
                $this->period->period_end->format('d/m'),
                'R$ '.number_format((float) $this->period->total_amount, 2, ',', '.'),
            ))
            ->view('emails.flex-billing-cycle');
    }
}
