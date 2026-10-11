<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotional_notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            // Link opcional pra onde o cliente cai ao clicar (ex: uma
            // categoria em promoção, um cupom específico) — sem isso a
            // notificação é só um aviso, sem ação.
            $table->string('link')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Preenchido pelo job depois que o disparo termina (0 até lá) —
            // não é "quantos clientes existem agora", é "pra quantos foi
            // enviado de fato nesse disparo".
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotional_notification_campaigns');
    }
};
