<?php

namespace App\Modules\Operacional\Mail;

use Illuminate\Mail\Mailable;

/** Alerta de pedidos cancelados depois de separados (pedido 2026-10-10). */
class CanceladosSeparados extends Mailable
{
    /** @param list<array> $pedidos */
    public function __construct(public readonly array $pedidos)
    {
    }

    public function build(): self
    {
        $n = count($this->pedidos);

        return $this
            ->subject("⚠️ {$n} ".($n === 1 ? 'pedido separado foi cancelado' : 'pedidos separados foram cancelados').' — não envie')
            ->view('emails.cancelados-separados');
    }
}
