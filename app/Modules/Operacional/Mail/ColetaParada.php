<?php

namespace App\Modules\Operacional\Mail;

use Illuminate\Mail\Mailable;

/** Uma coleta parou de chegar ou voltou (loja:vigiar-coletas, 24/11/2026). */
class ColetaParada extends Mailable
{
    public function __construct(
        public readonly string $nome,
        public readonly bool $voltou,
        public readonly ?string $ultima,
        public readonly int $limiteHoras,
        public readonly ?string $detalhe,
        public readonly string $dica,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject($this->voltou ? "✅ Coleta voltou: {$this->nome}" : "⚠️ Coleta parada: {$this->nome}")
            ->view('emails.coleta-parada');
    }
}
