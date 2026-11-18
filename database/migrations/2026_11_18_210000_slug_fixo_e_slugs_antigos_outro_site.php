<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endereço do produto nos sites irmãos (FerraKora, Beauty Kora), pedido do
 * Lira 2026-11-18: o slug sai do título (Str::slug), então trocar o título
 * derrubava o link antigo (404 no anúncio da Meta).
 *
 * - slug: endereço FIXO opcional por produto do site; preenchido, vale ele
 *   em vez do título (produto com campanha muda de título sem mudar a URL).
 * - outro_site_slugs_antigos: cada slug que o produto deixou de usar no site;
 *   a API acha o produto por ele e o site manda para o endereço atual.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ferrakora_produtos', 'beautykora_produtos'] as $tabela) {
            if (Schema::hasTable($tabela) && ! Schema::hasColumn($tabela, 'slug')) {
                Schema::table($tabela, function (Blueprint $table) {
                    $table->string('slug', 191)->nullable()->after('titulo');
                });
            }
        }

        if (! Schema::hasTable('outro_site_slugs_antigos')) {
            Schema::create('outro_site_slugs_antigos', function (Blueprint $table) {
                $table->id();
                $table->string('site', 20);
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('slug', 191);
                $table->timestamps();
                $table->unique(['site', 'slug']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outro_site_slugs_antigos');

        foreach (['ferrakora_produtos', 'beautykora_produtos'] as $tabela) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, 'slug')) {
                Schema::table($tabela, fn (Blueprint $table) => $table->dropColumn('slug'));
            }
        }
    }
};
