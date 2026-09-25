<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\CorreiosCancelamento;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mesmo cancelamento do botão do menu Correios, pela linha de comando:
 * `php artisan correios:cancelar AP538309458BR` (código do objeto ou id
 * dos Correios).
 */
class CancelCorreiosPrePostagem extends Command
{
    protected $signature = 'correios:cancelar {codigo : Código do objeto (ex: AP538309458BR) ou id da pré-postagem nos Correios}';

    protected $description = 'Cancela uma pré-postagem nos Correios pela API e marca como cancelada no KazaKora';

    public function handle(CorreiosCancelamento $cancelamento): int
    {
        $codigo = (string) $this->argument('codigo');

        $prePostagem = CorreiosPrePostagem::query()
            ->where('codigo_objeto', $codigo)
            ->orWhere('correios_id', $codigo)
            ->latest('id')
            ->first();

        if (! $prePostagem) {
            $this->error("Nenhuma pré-postagem com o código {$codigo}.");

            return self::FAILURE;
        }

        try {
            $cancelamento->cancelar($prePostagem);
        } catch (Throwable $exception) {
            $this->error('Não cancelou: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Pré-postagem {$codigo} (pedido #{$prePostagem->order_id}) cancelada nos Correios.");

        return self::SUCCESS;
    }
}
