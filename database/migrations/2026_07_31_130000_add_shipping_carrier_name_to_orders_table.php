<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Preenchido tanto pra ShippingMethod estático (nome do método)
            // quanto pra cotação ao vivo do Melhor Envio (nome da
            // transportadora/serviço) — nesse segundo caso shipping_method_id
            // fica null, então isso é o único registro legível de "como foi
            // enviado" pro pedido.
            $table->string('shipping_carrier_name')->nullable()->after('shipping_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipping_carrier_name');
        });
    }
};
