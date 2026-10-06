<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controle de devoluções e reclamações de todas as plataformas (pedido do
 * usuário 2026-10-06): da abertura ao veredito de quem conferiu o produto.
 * ML e Shopee vêm das APIs (devolucoes:sincronizar); TikTok/Amazon, que não
 * têm API disponível, são registrados à mão na mesma tela.
 *
 * marketplace_returns guarda o estado atual; marketplace_return_events, cada
 * mudança — nenhum status se perde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_returns', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 30);
            $table->string('external_id', 64)->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id', 64)->nullable();
            $table->string('kind', 20)->default('devolucao');
            $table->string('reason_code', 64)->nullable();
            $table->string('reason_label')->nullable();
            $table->string('situacao', 30);
            $table->string('platform_status', 64)->nullable();
            $table->string('return_status', 64)->nullable();
            $table->string('money_status', 64)->nullable();
            $table->string('refund_at', 30)->nullable();
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->string('tracking_number', 64)->nullable();
            $table->timestamp('respond_due_at')->nullable();
            $table->timestamp('receive_due_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('resolution')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verdict', 30)->nullable();
            $table->text('verdict_note')->nullable();
            $table->timestamp('verdict_at')->nullable();
            $table->foreignId('verdict_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('manual')->default(false);
            $table->json('alerts_notified')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
            $table->index(['situacao', 'respond_due_at']);
        });

        Schema::create('marketplace_return_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_return_id')->constrained()->cascadeOnDelete();
            $table->string('situacao', 30)->nullable();
            $table->string('description');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('happened_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_return_events');
        Schema::dropIfExists('marketplace_returns');
    }
};
