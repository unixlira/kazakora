<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Relatórios de pagamento da Amazon recebidos pela API (agente da Amazon, 2026-10-12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amazon_report_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('nome_original');
            $table->string('caminho');
            $table->string('sha256', 64)->unique();
            $table->unsignedBigInteger('tamanho');
            $table->string('mime', 120)->nullable();
            $table->string('tipo', 60)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('status', 20)->default('recebido');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amazon_report_uploads');
    }
};
