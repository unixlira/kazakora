<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vender › Promoções (pedido do Lira 2026-10-21): uma linha por promoção
 * (Airfryer e PDFs) com as regras que ele define pelo admin. `regras` guarda
 * as chaves livres (chave => valor).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocoes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('nome');
            $table->boolean('ativa')->default(false);
            $table->dateTime('inicio')->nullable();
            $table->dateTime('fim')->nullable();
            $table->decimal('valor_minimo', 10, 2)->nullable();
            $table->json('regras')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });

        foreach ([['airfryer', 'Promoção Airfryer'], ['pdfs', 'Promoção PDFs']] as [$slug, $nome]) {
            DB::table('promocoes')->insert(['slug' => $slug, 'nome' => $nome, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promocoes');
    }
};
