<?php

namespace App\Modules\Inventory\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Notifications\LowStockNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Pedido explícito 2026-08-16: avisa toda vez que um admin loga se algum
 * produto está com estoque baixo (≤2 unidades) — "pra comprar". Roda no
 * login (ver AuthenticatedSessionController::store()), não num job
 * agendado: o alerta é sobre ATENÇÃO AGORA, não sobre histórico.
 */
class LowStockAlertService
{
    /** "2 unidades" — pedido explícito, não um valor configurável por enquanto. */
    private const THRESHOLD = 2;

    /**
     * @return array<int, array{id: int, name: string, sku: string, stock: int}> vazio quando nada está baixo.
     */
    public function checkAndNotify(User $user): array
    {
        $lowStock = Product::query()
            ->where('is_active', true)
            ->where('stock', '<=', self::THRESHOLD)
            ->orderBy('stock')
            ->get(['id', 'name', 'sku', 'stock']);

        if ($lowStock->isEmpty()) {
            return [];
        }

        // Não duplica notificação a cada login enquanto o mesmo estoque
        // baixo continuar sem resolver — apaga a anterior (se ainda não
        // lida) e substitui por uma atualizada, refletindo o estoque atual
        // (um produto pode ter entrado ou saído da lista desde o último
        // login). Uma já LIDA/dispensada pelo usuário não é tocada — a
        // única forma de reaparecer é o próprio botão de excluir não ter
        // sido usado.
        $user->notifications()
            ->where('type', LowStockNotification::class)
            ->whereNull('read_at')
            ->delete();

        Notification::send($user, new LowStockNotification($lowStock));

        return $lowStock->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'stock' => $product->stock,
        ])->all();
    }
}
