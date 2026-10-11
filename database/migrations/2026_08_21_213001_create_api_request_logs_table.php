<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de auditoria da API pública — pedido explícito 2026-08-21 (junto
 * com a API em si): diferente do AuditLog existente (que registra mudança
 * de MODEL via o trait Auditable), isso é tráfego HTTP cru — todo request
 * que passar pelo middleware LogApiRequest (rotas api/v1/*) grava uma
 * linha aqui, sucesso ou erro. Existe pra dar visibilidade real de "o que
 * esse parceiro andou fazendo" sem precisar vasculhar log de arquivo, e
 * pra detectar uso anômalo (muitos 401/403/429 seguidos, por exemplo).
 * partner_id nullable de propósito: uma tentativa de acesso SEM token
 * válido nenhum ainda vale a pena registrar (tentativa de invasão/token
 * errado), só não tem um parceiro real pra associar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_partner_id')->nullable()->constrained('api_partners')->nullOnDelete();
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status_code');
            $table->string('ip', 45);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->index(['api_partner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
