<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAdPhotoBrief;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Entrega ao worker do Homer o próximo pacote de fotos de anúncio da fila do
 * ChatGPT da Cora Box (2026-10-25). Imprime JSON numa linha; sem pacote na
 * fila imprime {}. Só devolve as artes que ainda faltam.
 */
class FotosAnuncioProxima extends Command
{
    protected $signature = 'fotos-anuncio:proxima';

    protected $description = 'Pega o próximo pacote de fotos de anúncio na fila do ChatGPT (Cora Box) e imprime em JSON';

    public function handle(): int
    {
        $brief = MarketplaceAdPhotoBrief::reivindicarProximo();

        if (! $brief) {
            $this->line('{}');

            return self::SUCCESS;
        }

        $referencia = $brief->image_path && Storage::disk('public')->exists($brief->image_path)
            ? Storage::disk('public')->path($brief->image_path)
            : null;

        $this->line(json_encode([
            'uuid' => $brief->uuid,
            'marketplace' => $brief->marketplace,
            'produto' => $brief->product_name,
            'referencia' => $referencia,
            'fidelidade' => $brief->immutable_notes,
            'itens' => collect($brief->matrix ?? [])
                ->reject(fn (array $item): bool => filled($item['image_url'] ?? null))
                ->map(fn (array $item): array => [
                    'chave' => $item['key'],
                    'titulo' => $item['title'] ?? $item['key'],
                    'prompt' => $item['prompt'] ?? '',
                ])
                ->values()
                ->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
