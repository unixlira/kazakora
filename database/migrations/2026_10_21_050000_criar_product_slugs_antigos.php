<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Endereço antigo de produto (Lira, 2026-10-20): renomear o produto no admin
 * troca o slug e o link velho (anúncio, WhatsApp, e-mail, Google) dava 404.
 * A parafusadeira 53 saiu de "...-36v-bivolt-2-bateria-maleta-kicool" para
 * "...-48v-2-baterias-maleta-carregador-bivolt". Cada slug abandonado fica
 * aqui e a rota de produto responde 301 para o slug atual.
 *
 * Carga inicial: trocas de slug que o audit_logs já registrou.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_slugs_antigos')) {
            Schema::create('product_slugs_antigos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')
            ->where('entity', 'Product')
            ->where('action', 'update')
            ->where('old_values', 'like', '%"slug"%')
            ->orderBy('id')
            ->get(['entity_id', 'old_values', 'new_values', 'created_at'])
            ->each(function ($log) {
                $antigo = json_decode((string) $log->old_values, true)['slug'] ?? null;
                $novo = json_decode((string) $log->new_values, true)['slug'] ?? null;
                if (! $antigo || $antigo === $novo) {
                    return;
                }
                if (! DB::table('products')->where('id', $log->entity_id)->exists()) {
                    return;
                }
                // Slug que voltou a ser de um produto não vira redirecionamento.
                if (DB::table('products')->where('slug', $antigo)->exists()) {
                    return;
                }

                DB::table('product_slugs_antigos')->updateOrInsert(
                    ['slug' => $antigo],
                    ['product_id' => $log->entity_id, 'created_at' => $log->created_at, 'updated_at' => $log->created_at],
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_slugs_antigos');
    }
};
