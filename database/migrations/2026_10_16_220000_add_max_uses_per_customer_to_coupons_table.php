<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // Número de usos por pessoa (pedido 2026-10-16): conta pela conta,
            // e-mail e CPF do comprador. Null = sem limite por pessoa.
            $table->unsignedSmallInteger('max_uses_per_customer')->nullable()->after('max_uses');
        });

        // Quem já estava com "1 por cliente" passa a ter limite 1.
        DB::table('coupons')->where('one_per_customer', true)->update(['max_uses_per_customer' => 1]);
    }

    public function down(): void
    {
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('max_uses_per_customer'));
    }
};
