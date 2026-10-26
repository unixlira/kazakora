<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\AnuncioClaudePedido;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recebe pela entrada padrão o texto que o Claude escreveu para um pedido da
 * fila (2026-10-25) e grava como o Gemini gravaria. Resposta que não passa
 * nas conferências marca o pedido como falhou e sai com erro.
 */
class AnuncioClaudeResponder extends Command
{
    protected $signature = 'anuncio-claude:responder {pedido : id do pedido}';

    protected $description = 'Grava a resposta do Claude (JSON pela entrada padrão) num pedido da fila de anúncio';

    public function handle(AnuncioConteudoService $servico): int
    {
        $pedido = AnuncioClaudePedido::query()->find($this->argument('pedido'));

        if (! $pedido || $pedido->status !== AnuncioClaudePedido::STATUS_GERANDO) {
            $this->error('Pedido não encontrado ou não está sendo gerado.');

            return self::FAILURE;
        }

        $texto = (string) stream_get_contents(STDIN);

        try {
            $servico->aplicarRespostaClaude($pedido, $texto);
        } catch (Throwable $exception) {
            $pedido->update([
                'status' => AnuncioClaudePedido::STATUS_FALHOU,
                'erro' => Str::limit($exception->getMessage(), 1000),
                'concluido_em' => now(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $pedido->refresh();
        $this->info("#{$pedido->id} {$pedido->tipo} do produto {$pedido->product_id}: pronto".($pedido->erro ? " ({$pedido->erro})" : '').'.');

        return self::SUCCESS;
    }
}
