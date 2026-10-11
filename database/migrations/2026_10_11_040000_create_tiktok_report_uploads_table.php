<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Relatórios do TikTok recebidos pela API para a auditoria financeira (2026-10-11). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_report_uploads', function (Blueprint $table) {
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
        Schema::dropIfExists('tiktok_report_uploads');
    }
};
