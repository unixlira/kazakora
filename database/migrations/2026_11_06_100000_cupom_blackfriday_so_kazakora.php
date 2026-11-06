<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido do Lira 2026-11-06: cupom BLACKFRIDAY de 5%, só na KazaKora. A
     * faixa do checkout (config loja.cupom_faixa) mostra o código no lugar do
     * pop-up do BEMVINDO; o cliente copia e aplica, nada entra sozinho.
     * coupons.so_kazakora: o checkout da FerraKora e da Beauty Kora (API
     * CheckoutOutroSite) recusa o cupom. Sem limite e sem validade por
     * enquanto (a data de fim fica para o Lira, antes da produção).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('coupons', 'so_kazakora')) {
            Schema::table('coupons', fn (Blueprint $table) => $table->boolean('so_kazakora')->default(false)->after('is_active'));
        }

        if (! DB::table('coupons')->where('code', 'BLACKFRIDAY')->exists()) {
            DB::table('coupons')->insert([
                'code' => 'BLACKFRIDAY',
                'name' => 'Black Friday: 5% em qualquer pagamento, só na KazaKora (faixa do checkout)',
                'discount_type' => 'percentage',
                'discount_value' => 5,
                'min_order_value' => null,
                'max_uses' => null,
                'one_per_customer' => false,
                'is_active' => true,
                'so_kazakora' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Desliga o BLACKFRIDAY (não apaga: pode ter pedido com ele) e tira a coluna. */
    public function down(): void
    {
        DB::table('coupons')->where('code', 'BLACKFRIDAY')->update(['is_active' => false, 'updated_at' => now()]);

        if (Schema::hasColumn('coupons', 'so_kazakora')) {
            Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('so_kazakora'));
        }
    }
};
