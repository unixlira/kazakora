<?php

namespace App\Modules\Admin\Jobs;

use App\Modules\Admin\Services\FabricaCriativosKazakora;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Roteiro completo de um lote de criativos de Oportunidades › KazaKora (Gemini, em fila). */
class GerarRoteiroCriativos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 105;

    public function __construct(public readonly string $lote)
    {
    }

    public function handle(FabricaCriativosKazakora $fabrica, GeminiClient $gemini): void
    {
        // Uma leva por execução; se faltar, a próxima vai para a fila.
        if ($fabrica->roteirizar($this->lote, $gemini)) {
            self::dispatch($this->lote);
        }
    }

    public function failed(Throwable $erro): void
    {
        app(FabricaCriativosKazakora::class)->falhou($this->lote, $erro->getMessage());
    }
}
