<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cookies e LGPD (pedido 2026-10-10) — ver docs/privacidade-e-cookies.md.
 * - consentimentos_cookies: prova do OK do cliente (LGPD art. 8º, § 2º).
 * - site_visits: origem da visita (UTM), tipo de aparelho e se a visita foi
 *   com o OK (sem OK não há cookie de visitante nem navegador completo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consentimentos_cookies', function (Blueprint $table) {
            $table->id();
            $table->string('visitante_id', 36)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('versao', 20);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('aceito_em')->index();
        });

        Schema::table('site_visits', function (Blueprint $table) {
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('dispositivo', 10)->nullable();
            $table->boolean('consentiu')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropColumn(['utm_source', 'utm_medium', 'utm_campaign', 'dispositivo', 'consentiu']);
        });

        Schema::dropIfExists('consentimentos_cookies');
    }
};
