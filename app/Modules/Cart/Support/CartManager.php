<?php

namespace App\Modules\Cart\Support;

use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

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
        CartSnapshot::where('session_id', $this->session->getId())->delete();
    }

    public function items(): Collection
    {
        $raw = $this->raw();

        if (empty($raw)) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', array_keys($raw))
            ->with('quantityDiscounts', 'images')
            ->get()
            ->map(fn (Product $product) => [
                'product' => $product,
                'quantity' => $quantity = min($raw[$product->id], $product->stock),
                'subtotal' => round($product->unitPriceForQuantity($quantity) * $quantity, 2),
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
        $this->syncSnapshot($items);
    }

    /**
     * Espelha o carrinho numa tabela própria só pra visibilidade agregada
     * (dashboard admin) — a sessão continua sendo a fonte de verdade real do
     * carrinho. Sem item = sem registro, então "existe snapshot" já significa
     * "carrinho ativo", sem precisar de outro filtro no lado de quem lê.
     */
    private function syncSnapshot(array $items): void
    {
        if (empty($items)) {
            CartSnapshot::where('session_id', $this->session->getId())->delete();

            return;
        }

        $email = Auth::user()?->email;

        $snapshot = CartSnapshot::updateOrCreate(
            ['session_id' => $this->session->getId()],
            [
                'user_id' => Auth::id(),
                'items_count' => array_sum($items),
                'total' => $this->total(),
                // Carrinho abandonado (pedido 2026-10-10): o que tem dentro e
                // quando mexeu por último. Mexeu = os lembretes recomeçam.
                'itens' => $items,
                'ultima_atividade_em' => now(),
                'lembretes_enviados' => 0,
                'ultimo_lembrete_em' => null,
                ...($email ? ['email' => $email] : []),
            ],
        );

        $this->descartarCarrinhosAntigos($snapshot);
    }

    /**
     * Link do e-mail de carrinho abandonado: devolve os produtos daquele
     * carrinho para a sessão atual (outro aparelho, sessão expirada) sem
     * mexer no que já estiver aqui, e herda o e-mail e a contagem de
     * lembretes — a sequência não recomeça do zero.
     */
    public function restaurar(CartSnapshot $antigo): void
    {
        $items = $this->raw();
        $ativos = Product::query()->whereIn('id', array_keys($antigo->itens ?? []))->where('is_active', true)->pluck('id')->all();

        foreach ($antigo->itens ?? [] as $productId => $quantity) {
            if (in_array((int) $productId, $ativos, true) && ! isset($items[$productId])) {
                $items[$productId] = (int) $quantity;
            }
        }

        if (empty($items)) {
            return;
        }

        $herdado = [
            'email' => $antigo->email,
            'lembretes_enviados' => $antigo->lembretes_enviados,
            'ultimo_lembrete_em' => $antigo->ultimo_lembrete_em,
            'lembretes_parados' => $antigo->lembretes_parados,
        ];

        $this->save($items);

        $atual = CartSnapshot::where('session_id', $this->session->getId())->first();
        $atual?->forceFill([...$herdado, 'email' => $atual->email ?? $herdado['email']])->save();

        if ($atual && $atual->id !== $antigo->id) {
            $antigo->delete();
        }
    }

    /**
     * Visitante digitou o e-mail no checkout: o carrinho dele passa a poder
     * receber os lembretes de carrinho abandonado.
     */
    public function lembrarEmail(string $email): void
    {
        $snapshot = CartSnapshot::where('session_id', $this->session->getId())->first();

        if ($snapshot) {
            $snapshot->forceFill(['email' => mb_strtolower(trim($email))])->save();
            $this->descartarCarrinhosAntigos($snapshot);
        }
    }

    /** Um carrinho por cliente: o de outra sessão (antes de entrar na conta, outro aparelho) sai. */
    private function descartarCarrinhosAntigos(CartSnapshot $snapshot): void
    {
        if (! $snapshot->email && ! $snapshot->user_id) {
            return;
        }

        CartSnapshot::query()
            ->where('id', '!=', $snapshot->id)
            ->where(fn ($query) => $query
                ->when($snapshot->email, fn ($q) => $q->orWhere('email', $snapshot->email))
                ->when($snapshot->user_id, fn ($q) => $q->orWhere('user_id', $snapshot->user_id)))
            ->delete();
    }
}
