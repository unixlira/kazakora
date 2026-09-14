<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quebra dos descontos bancados pela PLATAFORMA, separados do desconto que
 * o lojista deu (`seller_discounts`): sem isso não dá pra dizer quanto do
 * desconto saiu do nosso bolso e quanto saiu do bolso do canal.
 *
 * Reconstrução, igual à migration que cria a tabela — ver o comentário dela.
 * Em produção as colunas já existem; hasColumn() deixa a migration no-op.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'platform_product_discounts',
        'platform_coupon_discounts',
        'platform_coupon_discount_refunds',
        'platform_shipping_discounts',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('marketplace_settlement_details')) {
            return;
        }

        Schema::table('marketplace_settlement_details', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('marketplace_settlement_details', $column)) {
                    $table->decimal($column, 12, 2)->default(0);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketplace_settlement_details')) {
            return;
        }

        Schema::table('marketplace_settlement_details', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
