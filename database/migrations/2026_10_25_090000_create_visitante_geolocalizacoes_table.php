<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localização aproximada do visitante pelo IP, uma linha por sessão
 * (pedido 2026-10-24): latitude, longitude e CEP aproximado, para depois
 * mostrar o frete já com o lugar do cliente. Por enquanto só grava.
 *
 * sessao: o mesmo código da sessão de site_visits.visitor_id sem OK
 * ("s-" + sha256 do id da sessão, nunca o id da sessão em si). Nos outros
 * sites (FerraKora, Beauty Kora) é o visitor_id da visita, tirado do IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitante_geolocalizacoes', function (Blueprint $table) {
            $table->id();
            $table->string('site', 20)->default('kazakora');
            $table->string('sessao', 40);
            $table->string('visitor_id', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('fonte', 20); // cloudflare | dbip
            $table->string('pais', 2)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cidade', 120)->nullable();
            $table->string('cep', 9)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('fuso', 60)->nullable();
            $table->timestamps();

            $table->unique(['site', 'sessao']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitante_geolocalizacoes');
    }
};
