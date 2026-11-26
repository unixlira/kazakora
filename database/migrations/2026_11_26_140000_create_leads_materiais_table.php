<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads de materiais gratuitos (pedido do Lira 26/11/2026): quem baixou o
 * Guia de Viagem de Bolso na FerraKora (/guia-de-viagem). Um registro por
 * material + e-mail; cadastrar de novo atualiza e soma em `cadastros`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads_materiais', function (Blueprint $table) {
            $table->id();
            $table->string('material', 40);
            $table->string('site', 20)->default('ferrakora');
            $table->string('nome', 120);
            $table->string('email', 160);
            // 55 + DDD + número, só dígitos (13).
            $table->string('telefone', 13)->index();
            $table->string('ddd', 2)->nullable();
            // Lugar aproximado pelo IP (Cloudflare ou base DB-IP do servidor), sem GPS.
            $table->string('uf_ip', 2)->nullable();
            $table->string('cidade_ip', 120)->nullable();
            $table->string('ddd_sugerido', 2)->nullable();
            // Prova do aceite (LGPD art. 8º § 2º).
            $table->boolean('aceita_contato')->default(false);
            $table->string('consentimento_versao', 20)->nullable();
            $table->text('consentimento_texto')->nullable();
            $table->timestamp('consentimento_em')->nullable();
            $table->timestamp('descadastrado_em')->nullable();
            // De onde veio: ?origem= da rota e as UTMs do link.
            $table->string('origem', 40)->nullable()->index();
            $table->string('utm_source', 150)->nullable();
            $table->string('utm_medium', 150)->nullable();
            $table->string('utm_campaign', 150)->nullable();
            $table->string('utm_content', 150)->nullable();
            $table->string('utm_term', 150)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('cadastros')->default(1);
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('ultimo_download_em')->nullable();
            $table->timestamp('email_enviado_em')->nullable();
            $table->timestamps();

            $table->unique(['material', 'email']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads_materiais');
    }
};
