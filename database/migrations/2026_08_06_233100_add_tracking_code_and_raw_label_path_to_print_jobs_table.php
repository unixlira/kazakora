<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cópia direta dos mesmos campos novos de channel_shipments (ver migration
 * irmã) — o agente de impressão (KoraSync) só fala com print_jobs, nunca
 * com channel_shipments, então precisa ter tudo que precisa pra arquivar
 * (canal, rastreio, arquivo bruto) aqui também, sem join extra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->string('tracking_code')->nullable()->after('channel');
            $table->string('raw_label_path')->nullable()->after('label_path');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropColumn(['tracking_code', 'raw_label_path']);
        });
    }
};
