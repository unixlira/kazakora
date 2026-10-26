<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\AnuncioClaudePedido;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** O worker do Claude não conseguiu escrever o pedido (2026-10-25): guarda o motivo. */
class AnuncioClaudeFalha extends Command
{
    protected $signature = 'anuncio-claude:falha {pedido : id do pedido} {motivo : o que deu errado}';

    protected $description = 'Marca um pedido da fila de anúncio do Claude como falhou';

    public function handle(): int
    {
        $pedido = AnuncioClaudePedido::query()->find($this->argument('pedido'));

        if (! $pedido) {
            $this->error('Pedido não encontrado.');

            return self::FAILURE;
        }

        $pedido->update([
            'status' => AnuncioClaudePedido::STATUS_FALHOU,
            'erro' => Str::limit((string) $this->argument('motivo'), 1000),
            'concluido_em' => now(),
        ]);
        $this->info("#{$pedido->id} marcado como falhou.");

        return self::SUCCESS;
    }
}
