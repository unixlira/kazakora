<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprovante de entrega ao entregador do Flex — a tela "conferida e
 * entregue" do KoraFlex, pedida pelo usuário em 2026-09-10.
 *
 * Guarda o que prova a passagem da caixa de uma mão pra outra: quais
 * pacotes, a hora, a assinatura feita com o dedo, uma foto tirada no
 * momento do envio e — antes de tudo isso — o registro de que a pessoa
 * LEU E CONCORDOU com a coleta desses dados.
 *
 * O consentimento tem coluna própria, com hora e com o texto exato que
 * foi mostrado na tela: assinatura e foto são dados pessoais do
 * entregador, que não é funcionário da loja. Guardar a versão do aviso
 * junto é o que permite dizer, meses depois, exatamente com o que ele
 * concordou — se o texto mudar, o recibo antigo continua provando o
 * texto antigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flex_pickup_receipts', function (Blueprint $table) {
            $table->id();

            // Quem levou, quando, e de qual aparelho saiu o registro.
            $table->string('carrier_name')->nullable();
            $table->timestamp('collected_at');
            $table->string('device')->nullable();

            // Consentimento: sem ele não existe foto nem assinatura.
            $table->timestamp('consented_at')->nullable();
            $table->text('consent_text')->nullable();

            // Caminhos no disco local (nunca públicos) — ver
            // FlexPickupService::entregar().
            $table->string('signature_path')->nullable();
            $table->string('photo_path')->nullable();

            // A lista de pedidos deste recibo, congelada no momento da
            // entrega: o pedido pode mudar de estado depois, o recibo não.
            $table->json('order_ids');
            $table->unsignedInteger('orders_count')->default(0);

            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('pickup_receipt_id')->nullable()->after('collected_at');
            $table->index('pickup_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['pickup_receipt_id']);
            $table->dropColumn('pickup_receipt_id');
        });

        Schema::dropIfExists('flex_pickup_receipts');
    }
};
