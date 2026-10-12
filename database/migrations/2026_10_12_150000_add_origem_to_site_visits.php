<?php

use App\Modules\Analytics\Support\OrigemVisita;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Origem da visita (pedido 2026-10-12, tela Visitas ao site):
 * - origem: chave de OrigemVisita (instagram, google_ads, direto, interno...)
 *   gravada na hora da visita. Null = visita antiga, sem dado.
 * - click_id_param / click_id: identificador de clique da URL (fbclid,
 *   gclid, ttclid...). O valor só com o OK de cookies; sem o OK fica só o
 *   nome do parâmetro.
 *
 * Visitas antigas com Referer ou UTM ganham a origem calculada; as sem
 * nenhum dos dois continuam null ("Sem dado": não dá para saber se foi
 * acesso direto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->string('origem', 20)->nullable()->index();
            $table->string('click_id_param', 20)->nullable();
            $table->string('click_id')->nullable();
        });

        DB::table('site_visits')
            ->whereNull('origem')
            ->where(fn ($q) => $q->whereNotNull('referer')->orWhereNotNull('utm_source'))
            ->select(['id', 'referer', 'utm_source', 'utm_medium', 'utm_campaign'])
            ->chunkById(1000, function ($linhas) {
                foreach ($linhas as $l) {
                    DB::table('site_visits')->where('id', $l->id)->update([
                        'origem' => OrigemVisita::classificar($l->referer, [
                            'utm_source' => $l->utm_source,
                            'utm_medium' => $l->utm_medium,
                            'utm_campaign' => $l->utm_campaign,
                        ]),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropIndex(['origem']);
            $table->dropColumn(['origem', 'click_id_param', 'click_id']);
        });
    }
};
