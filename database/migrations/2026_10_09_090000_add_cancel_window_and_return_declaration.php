<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do contador (Contabilidade Galícia, 2026-10-08): saber quais notas
 * foram canceladas fora do prazo de 24h (sujeitas à multa do RICMS-SP),
 * mandar o arquivo do evento de cancelamento e guardar a declaração de
 * devolução assinada pelo cliente pessoa física.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('cancelamento_extemporaneo')->default(false)->after('cancelada_em');
            $table->string('xml_cancelamento_path')->nullable()->after('cancelamento_extemporaneo');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('return_declaration_path')->nullable()->after('fiscal_additional_info');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['cancelamento_extemporaneo', 'xml_cancelamento_path']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('return_declaration_path');
        });
    }
};
