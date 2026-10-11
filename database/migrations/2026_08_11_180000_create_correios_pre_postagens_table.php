<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pré-postagens dos Correios geradas pela loja — pedido explícito
 * 2026-08-11: menu próprio com histórico (identificado por cliente + onde
 * comprou), tela de criação e impressão do QR Code de atendimento.
 *
 * `order_id` é opcional (pré-postagem pode ser feita sem pedido vinculado,
 * digitando os dados manualmente), mas os dados do destinatário/origem são
 * sempre gravados como snapshot aqui — se o pedido mudar depois (ou for
 * removido), o histórico da pré-postagem continua mostrando exatamente o
 * que foi enviado aos Correios no momento da geração.
 *
 * `qr_payload` é gerado e renderizado no navegador (vendorizado em
 * resources/js/vendor/qrcode-generator.mjs — ver CorreiosController), não
 * salvo como imagem no servidor: é só o protocolo/código de rastreio, então
 * regenerar o SVG a qualquer momento é gratuito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correios_pre_postagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // "Onde comprou" — mesmo vocabulário de Order::origin
            // (mercado_livre/shopee/tiktok_shop/amazon/shein/loja), snapshot
            // porque o pedido pode não existir (digitação manual).
            $table->string('origin')->nullable();
            $table->string('external_order_id')->nullable();

            // Snapshot do destinatário (não referencia Order pra sobreviver
            // mesmo se o pedido for excluído/editado depois).
            $table->string('customer_name');
            $table->string('customer_document')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('zip', 17);
            $table->string('street');
            $table->string('number', 6);
            $table->string('complement', 30)->nullable();
            $table->string('neighborhood', 30);
            $table->string('city', 30);
            $table->string('state', 2);

            // Dados do envio enviados na criação (RequestPrePostagemExternaDTO)
            $table->string('service_code', 10);
            $table->string('service_label')->nullable();
            $table->unsignedInteger('weight_grams');
            $table->json('content_items');

            $table->string('status', 20)->default('erro');
            $table->string('correios_id')->nullable();
            $table->string('codigo_objeto')->nullable();
            $table->string('qr_payload')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('customer_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correios_pre_postagens');
    }
};
