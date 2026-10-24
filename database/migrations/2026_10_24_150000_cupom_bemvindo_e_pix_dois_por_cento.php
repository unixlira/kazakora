<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pedido do Lira 2026-10-24: os 5% do Pix viram 3% + 2%. Cria o cupom
     * BEMVINDO (3%, sem limite, sem validade), que o checkout aplica sozinho
     * (config loja.cupom_automatico), e baixa o "Desconto Checkout" (só no
     * Pix) dos produtos que estavam com 5% para 2%. Produto com outro % ou
     * com valor em R$ fica como está.
     */
    public function up(): void
    {
        if (! DB::table('coupons')->where('code', 'BEMVINDO')->exists()) {
            DB::table('coupons')->insert([
                'code' => 'BEMVINDO',
                'name' => 'Boas-vindas: 3% em qualquer pagamento (entra sozinho no checkout)',
                'discount_type' => 'percentage',
                'discount_value' => 3,
                'min_order_value' => null,
                'max_uses' => null,
                'one_per_customer' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('products')
            ->where('checkout_discount_percentage', 5)
            ->whereNull('checkout_discount_amount')
            ->update(['checkout_discount_percentage' => 2, 'updated_at' => now()]);
    }

    /** Volta o Pix dos produtos com 2% para 5% e desliga o BEMVINDO (não apaga: pode ter pedido com ele). */
    public function down(): void
    {
        DB::table('products')
            ->where('checkout_discount_percentage', 2)
            ->whereNull('checkout_discount_amount')
            ->update(['checkout_discount_percentage' => 5, 'updated_at' => now()]);

        DB::table('coupons')->where('code', 'BEMVINDO')->update(['is_active' => false, 'updated_at' => now()]);
    }
};
