<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ofertas do Dia pelo admin (pedido 2026-10-18): checkbox no cadastro do
 * produto, junto de Destaque/Lançamento. Já nasce marcado nos fixos que
 * estavam no OFERTAS_FIXAS, para a home não mudar até o Lira mexer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_daily_offer')->default(false)->after('is_new_release');
        });

        $fixas = config('ofertas.fixas', []);
        if ($fixas) {
            DB::table('products')->whereIn('id', $fixas)->update(['is_daily_offer' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_daily_offer');
        });
    }
};
