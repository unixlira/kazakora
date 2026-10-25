<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Novo Pix de 24 h para pedido do site cancelado por Pix vencido (pedido
     * do Lira 2026-10-23, `pedidos:novo-pix`): o cliente paga pela página do
     * rastreio, longe do checkout, então o copia e cola e o QR Code ficam
     * guardados. `substituido_em` marca o Pix antigo trocado pelo novo, para
     * ele não travar a confirmação do pedido (OrderPaymentFinalizer).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('pix_copia_e_cola')->nullable()->after('expira_em');
            $table->mediumText('pix_qr_base64')->nullable()->after('pix_copia_e_cola');
            $table->timestamp('substituido_em')->nullable()->after('pix_qr_base64');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['pix_copia_e_cola', 'pix_qr_base64', 'substituido_em']);
        });
    }
};
