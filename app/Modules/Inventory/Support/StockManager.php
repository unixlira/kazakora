<?php

namespace App\Modules\Inventory\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Marketplace\Jobs\SyncProductStockToChannels;
use App\Notifications\OversellDetectedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
        $oversold = false;
        $availableBefore = 0;

        $movement = DB::transaction(function () use ($product, $delta, $type, $reason, $reference, &$oversold, &$availableBefore) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $availableBefore = $locked->stock;
            $newStock = max(0, $locked->stock + $delta);
            $oversold = $delta < 0 && $locked->stock < abs($delta);
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

        // Concorrência entre canais (duas vendas quase simultâneas em canais
        // diferentes pro mesmo produto) pode pedir mais do que existe —
        // adjust() já clampa em 0 acima, isso só avisa o admin pra tratar
        // manualmente o pedido que ficou sem estoque de verdade.
        if ($oversold) {
            $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new OversellDetectedNotification($product, abs($delta), $availableBefore, $reason ?? $type));
            }
        }

        return $movement;
    }
}
