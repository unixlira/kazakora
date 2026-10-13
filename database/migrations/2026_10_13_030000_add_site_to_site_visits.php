<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site de origem da visita (pedido do Lira 2026-10-12): o banco é o mesmo
 * para a loja e os outros sites do grupo, então site_visits ganha a coluna
 * site (kazakora, ferrakora, beautykora). Tudo o que já existe é da loja.
 *
 * Na produção a coluna já existe, nula (2026_10_13_025000): aqui os nulos
 * viram kazakora e a coluna ganha o padrão e o índice.
 *
 * As visitas do FerraKora que estavam em ferrakora_visits são copiadas para
 * site_visits com site = ferrakora. A tabela antiga fica (não é apagada) e
 * deixa de receber visitas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('site_visits', 'site')) {
            // Produção: a 025000 criou a coluna nula antes (pedido do Lira
            // 2026-10-12). Tudo o que ficou nulo é da loja.
            DB::table('site_visits')->whereNull('site')->update(['site' => 'kazakora']);
            Schema::table('site_visits', function (Blueprint $table) {
                $table->string('site', 20)->nullable(false)->default('kazakora')->change();
                $table->index(['site', 'created_at']);
            });
        } else {
            Schema::table('site_visits', function (Blueprint $table) {
                $table->string('site', 20)->default('kazakora')->after('id');
                $table->index(['site', 'created_at']);
            });
        }

        if (! Schema::hasTable('ferrakora_visits')) {
            return;
        }

        DB::table('ferrakora_visits')->orderBy('id')->chunkById(1000, function ($linhas) {
            DB::table('site_visits')->insert($linhas->map(fn ($l) => [
                'site' => 'ferrakora',
                'visitor_id' => $l->ip !== null ? substr(md5($l->ip), 0, 32) : 'anonimo',
                'path' => $l->path,
                'user_agent' => $l->user_agent,
                'ip' => $l->ip,
                'consentiu' => false,
                'created_at' => $l->created_at,
            ])->all());
        });
    }

    public function down(): void
    {
        DB::table('site_visits')->where('site', '!=', 'kazakora')->delete();

        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropIndex(['site', 'created_at']);
            $table->dropColumn('site');
        });
    }
};
