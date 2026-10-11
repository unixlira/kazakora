<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Services\Alinhamento\ColetorCatalogo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Alinhamento do catálogo Kazakora ↔ Shopee ↔ Bling com ajuda do Gemini
 * (pedido 2026-10-11: "nunca mais ter erro de emissão de nota por falta de
 * dados"). Etapas:
 *
 *  coletar   só lê as três fontes e grava o retrato em storage/app/alinhamento/
 *
 * O retrato fica em storage/app/alinhamento/{data-hora}/ e o mais recente
 * também em storage/app/alinhamento/ultimo.txt.
 */
class AlinharCatalogo extends Command
{
    protected $signature = 'catalogo:alinhar {etapa : coletar}
        {--fontes=kazakora,shopee,bling : Quais fontes coletar}';

    protected $description = 'De-para e alinhamento fiscal do catálogo entre Kazakora, Shopee e Bling (com Gemini)';

    public function handle(ColetorCatalogo $coletor): int
    {
        return match ($this->argument('etapa')) {
            'coletar' => $this->coletar($coletor),
            default => $this->fail('Etapa desconhecida.'),
        };
    }

    private function coletar(ColetorCatalogo $coletor): int
    {
        $pasta = 'alinhamento/'.now()->format('Ymd-His');
        $disco = Storage::disk('local');
        $fontes = array_map('trim', explode(',', (string) $this->option('fontes')));
        $progresso = function (string $fonte, int $n): void {
            if ($n % 25 === 0) {
                $this->line("  {$fonte}: {$n}…");
            }
        };

        foreach ($fontes as $fonte) {
            $this->info("Coletando {$fonte}…");
            $dados = match ($fonte) {
                'kazakora' => $coletor->kazakora(),
                'shopee' => $coletor->shopee($progresso),
                'bling' => $coletor->bling($progresso),
            };
            $disco->put("{$pasta}/{$fonte}.json", json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info('  '.count($dados)." registro(s) em {$pasta}/{$fonte}.json");
        }

        $disco->put('alinhamento/ultimo.txt', $pasta);

        return self::SUCCESS;
    }
}
