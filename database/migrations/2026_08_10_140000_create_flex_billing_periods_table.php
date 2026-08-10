<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico dos ciclos de cobrança do Mercado Envios Flex (quinzenal —
 * dia 1-15 e 16-fim do mês, pedido explícito 2026-08-10). Uma linha por
 * ciclo já fechado/notificado — serve tanto de fonte pro e-mail agendado
 * quanto pra tela de histórico. `cost_per_delivery` é um snapshot do valor
 * vigente NO MOMENTO do fechamento (não uma referência à Setting atual) —
 * se o valor por entrega mudar no futuro, ciclos antigos continuam
 * mostrando o valor real cobrado na época, não o atual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flex_billing_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('deliveries_count');
            $table->decimal('cost_per_delivery', 8, 2);
            $table->decimal('total_amount', 10, 2);
            $table->timestamp('email_sent_at')->nullable();
            $table->text('email_error')->nullable();
            $table->timestamps();

            $table->unique(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flex_billing_periods');
    }
};
