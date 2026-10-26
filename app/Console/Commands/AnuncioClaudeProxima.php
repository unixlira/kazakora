<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\AnuncioClaudePedido;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Entrega ao worker do Homer o próximo texto da fila do Claude (Gemini sem
 * resposta, 2026-10-25). Imprime JSON numa linha; fila vazia imprime {}.
 * As fotos vão como caminho no servidor, para o worker baixar por scp.
 */
class AnuncioClaudeProxima extends Command
{
    protected $signature = 'anuncio-claude:proxima';

    protected $description = 'Pega o próximo texto de anúncio da fila do Claude e imprime em JSON';

    public function handle(): int
    {
        $pedido = AnuncioClaudePedido::reivindicarProximo();

        if (! $pedido) {
            $this->line('{}');

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'id' => $pedido->id,
            'tipo' => $pedido->tipo,
            'produto' => $pedido->product_id,
            'sistema' => $pedido->sistema,
            'prompt' => $pedido->prompt,
            'imagens' => collect($pedido->imagens ?? [])
                ->filter(fn (array $imagem): bool => Storage::disk('public')->exists($imagem['caminho']))
                ->map(fn (array $imagem): array => [
                    'indice' => (int) $imagem['indice'],
                    'caminho' => Storage::disk('public')->path($imagem['caminho']),
                ])
                ->values()
                ->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
