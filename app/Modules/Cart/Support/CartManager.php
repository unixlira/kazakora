<?php

namespace App\Modules\Cart\Support;

use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

class CartManager
{
    private const SESSION_KEY = 'cart';

    public function __construct(private readonly Session $session)
    {
    }

    public function add(int $productId, int $quantity): void
    {
        $items = $this->raw();
        $items[$productId] = ($items[$productId] ?? 0) + $quantity;
        $this->save($items);
    }

    public function update(int $productId, int $quantity): void
    {
        $items = $this->raw();

        if ($quantity < 1) {
            unset($items[$productId]);
        } else {
            $items[$productId] = $quantity;
        }

        $this->save($items);
    }

    public function remove(int $productId): void
    {
        $items = $this->raw();
        unset($items[$productId]);
        $this->save($items);
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    public function items(): Collection
    {
        $raw = $this->raw();

        if (empty($raw)) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', array_keys($raw))
            ->get()
            ->map(fn (Product $product) => [
                'product' => $product,
                'quantity' => $quantity = min($raw[$product->id], $product->stock),
                'subtotal' => round($product->final_price * $quantity, 2),
            ]);
    }

    public function count(): int
    {
        return array_sum($this->raw());
    }

    public function total(): float
    {
        return round($this->items()->sum('subtotal'), 2);
    }

    private function raw(): array
    {
        return $this->session->get(self::SESSION_KEY, []);
    }

    private function save(array $items): void
    {
        $this->session->put(self::SESSION_KEY, $items);
    }
}
