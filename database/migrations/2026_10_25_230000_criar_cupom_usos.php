<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de quem já usou cada cupom pelo CPF/CNPJ (pedido do Lira
 * 2026-10-25, cupom BEMVINDO): gravado quando o pagamento é confirmado
 * (webhook → OrderPaymentFinalizer), nunca antes. Um documento por cupom.
 * Liga o "1 uso por cliente" no BEMVINDO e registra os pedidos já pagos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cupom_usos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->string('documento', 14);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['coupon_id', 'documento']);
        });

        DB::table('coupons')->where('code', 'BEMVINDO')->update(['one_per_customer' => true, 'updated_at' => now()]);

        // Pedidos pagos antes desta tabela existir.
        $cupons = DB::table('coupons')->pluck('id', 'code');
        DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->whereNotNull('orders.coupon_code')
            ->whereIn('orders.status', ['paid', 'shipped', 'completed'])
            ->orderBy('orders.id')
            ->get(['orders.id', 'orders.user_id', 'orders.coupon_code', 'orders.buyer_document', 'orders.updated_at', 'users.cpf'])
            ->each(function ($pedido) use ($cupons) {
                $documento = preg_replace('/\D/', '', (string) ($pedido->buyer_document ?: $pedido->cpf));
                $cupom = $cupons[$pedido->coupon_code] ?? null;
                if (! $cupom || ! in_array(strlen($documento), [11, 14], true)) {
                    return;
                }
                DB::table('cupom_usos')->insertOrIgnore([
                    'coupon_id' => $cupom, 'documento' => $documento, 'order_id' => $pedido->id,
                    'user_id' => $pedido->user_id, 'created_at' => $pedido->updated_at, 'updated_at' => $pedido->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('cupom_usos');
    }
};
