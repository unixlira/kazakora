<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Cart\Mail\CarrinhoAbandonado;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Cart\Support\LembreteCarrinho;
use App\Modules\Checkout\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Carrinho abandonado (pedido 2026-10-10): roda de 10 em 10 minutos e manda
 * o lembrete que estiver na hora (ver LembreteCarrinho). Para quando o
 * cliente compra, sai das promoções, pede para parar ou esvazia o carrinho
 * (aí o registro some).
 */
class LembrarCarrinhosAbandonados extends Command
{
    protected $signature = 'loja:carrinho-abandonado';

    protected $description = 'Manda os lembretes de carrinho abandonado que estão na hora';

    public function handle(): int
    {
        $enviados = 0;

        CartSnapshot::query()
            ->with('user')
            ->where('lembretes_parados', false)
            ->where('lembretes_enviados', '<', LembreteCarrinho::TOTAL)
            ->whereNotNull('ultima_atividade_em')
            ->where('ultima_atividade_em', '<=', now()->subMinutes(LembreteCarrinho::MINUTOS_PRIMEIRO))
            ->where(fn ($query) => $query->whereNotNull('email')->orWhereNotNull('user_id'))
            ->chunkById(100, function ($carrinhos) use (&$enviados) {
                foreach ($carrinhos as $carrinho) {
                    $enviados += $this->processar($carrinho) ? 1 : 0;
                }
            });

        $this->info("Lembretes enviados: {$enviados}");

        return self::SUCCESS;
    }

    private function processar(CartSnapshot $carrinho): bool
    {
        $email = $carrinho->email ?: $carrinho->user?->email;
        $cliente = $carrinho->user ?? User::where('email', $email)->first();

        if (! $email || empty($carrinho->itens) || $this->jaComprou($carrinho, $cliente, $email)
            || ($cliente && $cliente->recebe_promocoes === false)) {
            $carrinho->forceFill(['lembretes_parados' => true])->save();

            return false;
        }

        $quando = LembreteCarrinho::proximoEm($carrinho);
        if (! $quando || now()->lessThan($quando)) {
            return false;
        }

        $numero = $carrinho->lembretes_enviados + 1;

        try {
            Mail::to($email)->send(new CarrinhoAbandonado($carrinho, $numero));
        } catch (\Throwable $erro) {
            Log::warning('Lembrete de carrinho abandonado não saiu', ['carrinho' => $carrinho->id, 'erro' => $erro->getMessage()]);

            return false;
        }

        // Sem mexer no updated_at: "mexeu no carrinho" é só ultima_atividade_em.
        CartSnapshot::query()->whereKey($carrinho->id)->update([
            'lembretes_enviados' => $numero,
            'ultimo_lembrete_em' => now(),
        ]);

        return true;
    }

    private function jaComprou(CartSnapshot $carrinho, ?User $cliente, string $email): bool
    {
        return Order::query()
            ->where('created_at', '>=', $carrinho->ultima_atividade_em)
            ->where(fn ($query) => $query
                ->where('shipping_email', $email)
                ->when($cliente, fn ($q) => $q->orWhere('user_id', $cliente->id)))
            ->exists();
    }
}
