<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Marketplace\Jobs\SyncProductStockToChannels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockManager
{
    /**
     * Adjust a product's stock and record the movement in the same transaction.
     *
     * @param  int  $delta  Positive to increase stock, negative to decrease.
     */
    public function adjust(
        Product $product,
        int $delta,
        string $type,
        ?string $reason = null,
        ?Model $reference = null,
    ): StockMovement {
        $movement = DB::transaction(function () use ($product, $delta, $type, $reason, $reference) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $newStock = max(0, $locked->stock + $delta);
            $locked->update(['stock' => $newStock]);
            $product->setAttribute('stock', $newStock);

            $movement = $locked->stockMovements()->create([
                'type' => $type,
                'quantity' => $delta,
                'stock_after' => $newStock,
                'reason' => $reason,
                'user_id' => Auth::id(),
            ]);

            if ($reference) {
                $movement->reference()->associate($reference)->save();
            }

            return $movement;
        });

        SyncProductStockToChannels::dispatch($product)->afterCommit();

        return $movement;
    }
}
