<?php

namespace App\Modules\Operacional\Mail;

use Illuminate\Mail\Mailable;

/** Pedidos pagos sem NF-e autorizada e sem etiqueta impressa (pedido 2026-10-19). */
class NotaEEtiquetaPendentes extends Mailable
{
    /** @param list<array> $pedidos */
    public function __construct(public readonly array $pedidos)
    {
    }

    public function build(): self
    {
        $n = count($this->pedidos);

        return $this
            ->subject("🚨 {$n} ".($n === 1 ? 'pedido pago está' : 'pedidos pagos estão').' sem nota e sem etiqueta')
            ->view('emails.nota-etiqueta-pendentes');
    }
}
