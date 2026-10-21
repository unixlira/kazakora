<?php

namespace App\Console\Commands;

use App\Modules\WhatsApp\Services\ManuelaFollowUp;
use Illuminate\Console\Command;

/**
 * Acompanhamento da Manuela no WhatsApp (pedido 2026-10-20): cliente sem
 * responder há mais de 30 minutos recebe uma mensagem perguntando se ainda
 * tem dúvida. Regras em ManuelaFollowUp.
 */
class ManuelaAcompanhamento extends Command
{
    protected $signature = 'manuela:acompanhamento';

    protected $description = 'Manuela pergunta se o cliente ainda tem dúvida quando ele para de responder';

    public function handle(ManuelaFollowUp $followUp): int
    {
        $this->info($followUp->run().' acompanhamento(s) enviado(s).');

        return self::SUCCESS;
    }
}
