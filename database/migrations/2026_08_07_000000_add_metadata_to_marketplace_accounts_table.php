<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coluna genérica pra dados extras que só fazem sentido pra alguns canais
 * (ex: marketplace_id/region/sandbox da Amazon) sem precisar de uma tabela
 * dedicada por canal — mesmo espírito de ChannelWebhookLog.payload.
 * Aproveitado pra Amazon primeiro, mas fica disponível pra qualquer canal
 * futuro que precise guardar algo além de token/seller_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('connected_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
