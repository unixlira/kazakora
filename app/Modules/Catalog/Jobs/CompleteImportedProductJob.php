<?php

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductCompletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Completa (descrição, atributos, fotos, vídeo) o produto que acabou de
 * nascer de uma venda — ver ProductCompletionService. Fila, e não dentro
 * da importação do pedido: baixar vídeo leva segundos e a venda não pode
 * esperar por isso.
 */
class CompleteImportedProductJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly int $productId) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(ProductCompletionService $completion): void
    {
        $product = Product::query()->find($this->productId);

        if ($product) {
            $completion->complete($product);
        }
    }
}
