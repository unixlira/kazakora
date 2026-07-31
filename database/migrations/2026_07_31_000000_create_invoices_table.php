<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('ambiente')->default('homologacao');
            $table->unsignedInteger('serie')->default(1);
            $table->unsignedBigInteger('numero');
            $table->string('chave_acesso', 44)->unique()->nullable();
            $table->string('protocolo_autorizacao')->nullable();
            $table->timestamp('autorizada_em')->nullable();
            $table->text('motivo_rejeicao')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('danfe_path')->nullable();
            $table->string('protocolo_cancelamento')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->timestamp('cancelada_em')->nullable();
            $table->timestamps();

            $table->unique(['serie', 'numero', 'ambiente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
