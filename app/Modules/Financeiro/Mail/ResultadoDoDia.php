<?php

namespace App\Modules\Financeiro\Mail;

use Illuminate\Mail\Mailable;

/** E-mail diário de resultado (pedido 2026-10-10) — ver ResultadoDiario. */
class ResultadoDoDia extends Mailable
{
    public function __construct(public readonly array $resultado)
    {
    }

    public function build(): self
    {
        $lucro = 'R$ '.number_format((float) $this->resultado['dia']['lucro'], 2, ',', '.');

        return $this
            ->subject('Resultado de '.$this->resultado['data']->format('d/m').': lucro líquido '.$lucro)
            ->view('emails.resultado-do-dia');
    }
}
