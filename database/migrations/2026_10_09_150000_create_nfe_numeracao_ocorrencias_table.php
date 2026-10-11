<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fechamento fiscal do mês (contador, 2026-10-08/09):
 * - ocorrências na numeração da NF-e: número pulado por duplicidade na
 *   SEFAZ (539, alguém já usou o número e o XML dele falta pro contador) e
 *   faixas inutilizadas na SEFAZ;
 * - valor da UFESP por ano (multa do cancelamento fora do prazo), que antes
 *   era fixo no .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfe_numeracao_ocorrencias', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20); // duplicidade | inutilizacao
            $table->string('ambiente', 20);
            $table->unsignedSmallInteger('serie');
            $table->unsignedInteger('numero_inicial');
            $table->unsignedInteger('numero_final');
            $table->text('motivo')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('protocolo', 30)->nullable();
            $table->string('xml_path')->nullable();
            $table->timestamp('resolvido_em')->nullable();
            $table->timestamps();

            $table->index(['tipo', 'resolvido_em']);
            $table->index(['serie', 'numero_inicial']);
        });

        Schema::create('ufesp_valores', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('ano')->unique();
            $table->decimal('valor', 8, 2);
            $table->string('fonte')->nullable();
            $table->string('base_legal')->nullable();
            $table->timestamps();
        });

        $agora = now();
        DB::table('ufesp_valores')->insert([
            ['ano' => 2025, 'valor' => 37.02, 'base_legal' => 'Comunicado DICAR-88/24, de 17-12-2024', 'fonte' => 'https://legislacao.fazenda.sp.gov.br/Paginas/Comunicado-DICAR-88-de-2024.aspx', 'created_at' => $agora, 'updated_at' => $agora],
            ['ano' => 2026, 'valor' => 38.42, 'base_legal' => 'Comunicado DICAR-88/25, de 17-12-2025', 'fonte' => 'https://legislacao.fazenda.sp.gov.br/Paginas/Comunicado-DICAR-88-de-2025.aspx', 'created_at' => $agora, 'updated_at' => $agora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ufesp_valores');
        Schema::dropIfExists('nfe_numeracao_ocorrencias');
    }
};
