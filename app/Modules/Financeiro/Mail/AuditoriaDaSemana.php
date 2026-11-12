<?php

namespace App\Modules\Financeiro\Mail;

use Illuminate\Mail\Mailable;

/** E-mail da auditoria semanal do dinheiro que caiu (pedido 2026-11-12); ver AuditoriaSemanal. */
class AuditoriaDaSemana extends Mailable
{
    public function __construct(public readonly array $auditoria)
    {
    }

    public function build(): self
    {
        $de = $this->auditoria['de']->format('d/m');
        $ate = $this->auditoria['ate']->copy()->subDay()->format('d/m');
        $caiu = 'R$ '.number_format((float) $this->auditoria['total']['depositado'], 2, ',', '.');

        return $this
            ->subject("Auditoria da semana {$de} a {$ate}: caiu {$caiu}, veja quanto separar")
            ->view('emails.auditoria-semanal');
    }
}
