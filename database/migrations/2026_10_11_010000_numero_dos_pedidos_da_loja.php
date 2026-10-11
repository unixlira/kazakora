<?php

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos antigos da loja ganham o número no formato da Shopee (pedido
 * 2026-10-11), com a data em que foram feitos. Só preenche quem não tem.
 */
return new class extends Migration
{
    public function up(): void
    {
        $alfabeto = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        DB::table('orders')->where('origin', Order::ORIGIN_STORE)->whereNull('external_order_id')->orderBy('id')
            ->each(function ($pedido) use ($alfabeto) {
                do {
                    $sufixo = '';
                    for ($i = 0; $i < 8; $i++) {
                        $sufixo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
                    }
                    $numero = \Illuminate\Support\Carbon::parse($pedido->created_at)->format('ymd').$sufixo;
                } while (DB::table('orders')->where('origin', Order::ORIGIN_STORE)->where('external_order_id', $numero)->exists());

                DB::table('orders')->where('id', $pedido->id)->update(['external_order_id' => $numero]);
            });
    }

    public function down(): void
    {
        // Número é registro do pedido: não se apaga.
    }
};
