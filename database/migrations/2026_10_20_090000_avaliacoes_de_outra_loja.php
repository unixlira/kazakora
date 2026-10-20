<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avaliação feita em outra loja (pedido do Lira 2026-10-18): o site pode
 * mostrar avaliações de compradores do MESMO produto em outras lojas
 * (concorrentes da Shopee/ML, fornecedor Kicool), desde que cada uma tenha o
 * selo "Avaliação feita em outra loja" e não entre na nota da KazaKora.
 * Sem o selo seria avaliação falsa (CDC).
 *
 * - outra_loja: público (vai para o storefront, liga o selo e separa a média).
 * - origem_loja: nome real da loja de origem e link do anúncio, só para o
 *   admin (Review::$hidden).
 *
 * Data fix: as 11 copiadas do AliExpress e de outro anúncio da Shopee
 * (channel 'marketplace_reference', produtos 194 e 176) passam a ter o selo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('outra_loja')->default(false)->after('external_id')->index();
            $table->string('origem_loja', 500)->nullable()->after('outra_loja');
        });

        DB::table('reviews')
            ->where('channel', 'marketplace_reference')
            ->get(['id', 'external_id'])
            ->each(fn ($review) => DB::table('reviews')->where('id', $review->id)->update([
                'outra_loja' => true,
                'origem_loja' => trim('marketplace_reference '.$review->external_id),
            ]));
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['outra_loja']);
            $table->dropColumn(['outra_loja', 'origem_loja']);
        });
    }
};
