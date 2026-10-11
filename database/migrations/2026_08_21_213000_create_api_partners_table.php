<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-08-21: API pública pra parceiros externos
 * (integradores B2B), autenticação via token do Sanctum (ver
 * personal_access_tokens, migração publicada no mesmo dia). Um ApiPartner
 * NÃO é um User — não faz login no painel, só é dono de um ou mais tokens
 * de API. `abilities` reaproveita o MESMO vocabulário de permissões do
 * painel admin (App\Support\Rbac\Permissions::ALL) — um parceiro só recebe
 * as strings de permissão que o admin marcar explicitamente pra ele, e
 * cada token emitido carrega essa lista como as "abilities" do Sanctum
 * (checadas via middleware `abilities:...`/`tokenCan()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('contact_email')->nullable();
            $table->json('abilities')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_partners');
    }
};
