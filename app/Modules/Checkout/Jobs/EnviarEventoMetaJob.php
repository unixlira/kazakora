<?php

namespace App\Modules\Checkout\Jobs;

use App\Services\Meta\ConversoesMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manda à API de Conversões da Meta um evento já montado na requisição do
 * cliente (AddToCart, pedido 2026-10-18; InitiateCheckout, 2026-11-24). Na fila para não atrasar o carrinho.
 */
class EnviarEventoMetaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @param  array<string, mixed>  $evento */
    public function __construct(public readonly array $evento)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ConversoesMeta $conversoes): void
    {
        $conversoes->enviar($this->evento);
    }

    /** Esgotou as 3 tentativas: fica no log da Meta e no geral. */
    public function failed(Throwable $e): void
    {
        $dados = ['event_name' => $this->evento['event_name'] ?? null, 'event_id' => $this->evento['event_id'] ?? null, 'erro' => $e->getMessage()];
        ConversoesMeta::log()->error('meta_capi.desistiu', $dados);
        Log::error('meta_capi.desistiu', $dados);
    }
}
