<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preço próprio por canal — pedido do usuário 2026-10-07 ("cada canal tem
 * seu preço"). Null = usa o preço do produto, como sempre foi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_channel_listings', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('external_model_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_listings', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
