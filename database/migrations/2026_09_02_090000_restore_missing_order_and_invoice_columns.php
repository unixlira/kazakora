<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas que EXISTEM em produção mas não tinham migration nenhuma no git
 * (achado em 2026-09-02 rodando a suíte de testes: metade dela quebrava com
 * "no such column: orders.fiscal_operation_type", porque o banco montado a
 * partir das migrations não bate com o banco real).
 *
 * É exatamente o risco descrito no CLAUDE.md deste repositório: coisa
 * construída direto no servidor, fora do git. Sem isso, ambiente novo
 * (e a suíte inteira) nasce com o schema incompleto, e qualquer código que
 * use esses campos quebra — Order::scopeNonPurchaseReturn(), que filtra
 * devolução de compra, usa fiscal_operation_type e é chamado em toda a fila
 * do KoraSync.
 *
 * Tipos copiados do banco de produção (SHOW COLUMNS), pra ambiente novo
 * nascer idêntico ao que já roda. Guardado por hasColumn(): em produção
 * (onde as colunas já existem) a migration é no-op, não toca em nada.
 *
 * NÃO cobre as 5 tabelas que também só existem em produção
 * (marketplace_ad_photo_briefs, marketplace_campaign_metrics,
 * marketplace_settlement_details, whatsapp_conversations,
 * whatsapp_messages) — o código delas também não está no git, então
 * recriar só o schema não devolveria a funcionalidade; isso é uma
 * reconciliação à parte, não um efeito colateral desta migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'fiscal_operation_type')) {
                $table->string('fiscal_operation_type')->default('sale');
            }

            if (! Schema::hasColumn('orders', 'fiscal_nature_operation')) {
                $table->string('fiscal_nature_operation', 120)->nullable();
            }

            if (! Schema::hasColumn('orders', 'fiscal_finality')) {
                $table->unsignedTinyInteger('fiscal_finality')->default(1);
            }

            if (! Schema::hasColumn('orders', 'fiscal_referenced_nfe_key')) {
                $table->string('fiscal_referenced_nfe_key', 44)->nullable();
            }

            if (! Schema::hasColumn('orders', 'fiscal_additional_info')) {
                $table->text('fiscal_additional_info')->nullable();
            }

            if (! Schema::hasColumn('orders', 'buyer_state_registration')) {
                $table->string('buyer_state_registration')->nullable();
            }

            if (! Schema::hasColumn('orders', 'buyer_taxpayer_type')) {
                $table->string('buyer_taxpayer_type')->nullable();
            }

            if (! Schema::hasColumn('orders', 'waiting_for_product_at')) {
                $table->timestamp('waiting_for_product_at')->nullable();
            }

            if (! Schema::hasColumn('orders', 'waiting_for_product_until')) {
                $table->timestamp('waiting_for_product_until')->nullable();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'operation_type')) {
                $table->string('operation_type', 20)->default('saida');
            }

            if (! Schema::hasColumn('invoices', 'emitente_nome')) {
                $table->string('emitente_nome')->nullable();
            }

            if (! Schema::hasColumn('invoices', 'emitente_documento')) {
                $table->string('emitente_documento', 20)->nullable();
            }
        });
    }

    /**
     * Sem down(): estas colunas guardam dado fiscal real em produção (tipo
     * de operação da nota, dados do emitente, referência de NF-e de
     * devolução). Um rollback que as derrubasse perderia esse dado de
     * verdade, e elas nunca foram criadas por esta migration lá — ela só
     * existe pra ambiente NOVO nascer igual ao que já roda.
     */
    public function down(): void
    {
    }
};
