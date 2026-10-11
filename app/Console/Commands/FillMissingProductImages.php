<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductMediaBackfillService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Varre produtos sem nenhuma foto e busca no canal onde eles estão
 * anunciados.
 *
 * Pedido explícito 2026-09-05 ("irrevogável"): card de separação nunca
 * pode ficar sem imagem. Na primeira medição eram 26 produtos sem foto de
 * 87, e 66 dos 216 itens da fila apareciam sem imagem.
 *
 * Prioriza quem está em pedido aberto: é o card que o operador olha agora.
 */
class FillMissingProductImages extends Command
{
    protected $signature = 'catalog:fill-missing-images
        {--limit=200 : Máximo de produtos por execução}
        {--product= : Só este produto}
        {--dry-run : Lista o que faria, sem baixar nada}';

    protected $description = 'Busca no marketplace a foto de produtos que estão sem imagem';

    public function handle(ProductMediaBackfillService $backfill): int
    {
        $seco = (bool) $this->option('dry-run');

        $produtos = Product::query()
            ->whereDoesntHave('images')
            ->when($this->option('product'), fn ($q, $id) => $q->whereKey($id))
            // Produto que está em pedido aberto primeiro: é o card que o
            // operador tem na tela agora.
            ->orderByRaw('EXISTS (SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                WHERE oi.product_id = products.id AND o.status = ? AND o.packed_at IS NULL) DESC', ['paid'])
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->info($produtos->count().' produto(s) sem foto.');

        if ($seco) {
            foreach ($produtos as $produto) {
                $canais = $produto->channelListings()->pluck('channel')->implode(', ');
                $this->line("  #{$produto->id} {$produto->sku} — anúncios em: ".($canais ?: 'NENHUM canal'));
            }

            return self::SUCCESS;
        }

        $preenchidos = 0;
        $semCanal = 0;

        foreach ($produtos as $produto) {
            try {
                $fotos = $backfill->fill($produto);
            } catch (Throwable $exception) {
                $this->error("  #{$produto->id}: {$exception->getMessage()}");

                continue;
            }

            if ($fotos > 0) {
                $preenchidos++;
                $this->line("  #{$produto->id} {$produto->sku} — {$fotos} foto(s)");

                continue;
            }

            $semCanal++;
        }

        $this->info("Concluído: {$preenchidos} produto(s) com foto nova, {$semCanal} sem foto disponível em canal nenhum.");

        return self::SUCCESS;
    }
}
