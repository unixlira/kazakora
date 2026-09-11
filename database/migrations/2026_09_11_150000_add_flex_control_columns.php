<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controle dos envios Flex (pedido do usuário em 2026-09-11): ver o que
 * saiu, o que voltou, o que o entregador levou sem iniciar a rota — e
 * guardar o comprovante de forma que ele sirva de prova.
 *
 * POR QUE O STATUS DO CANAL PASSA A SER GRAVADO: até aqui o Kazakora lia o
 * shipment do Mercado Livre só pra decidir se o PEDIDO avançava pra
 * shipped/completed, e jogava o resto fora. Foi assim que a bicicleta do
 * pedido #1384 sumiu da vista: etiqueta impressa, caixa separada, venda
 * cancelada às 19:32 — e o ML nunca registrou `date_shipped`. O estoque foi
 * devolvido sozinho no sistema como se ela estivesse na prateleira. As
 * datas do `status_history` são exatamente o que mostra isso.
 *
 * O "resolvido" da devolução é de uma pessoa, não do canal: o ML dizer que
 * devolveu não põe o produto de volta na prateleira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            // Espelho do shipment do canal (status_history do ML).
            $table->string('channel_status', 40)->nullable()->after('status');
            $table->string('channel_substatus', 60)->nullable()->after('channel_status');
            $table->timestamp('channel_shipped_at')->nullable();
            $table->timestamp('channel_first_visit_at')->nullable();
            $table->timestamp('channel_delivered_at')->nullable();
            $table->timestamp('channel_not_delivered_at')->nullable();
            $table->timestamp('channel_returned_at')->nullable();
            $table->timestamp('channel_cancelled_at')->nullable();
            $table->timestamp('channel_status_checked_at')->nullable();

            // Quem confirmou que o produto voltou (ou que não vai voltar).
            $table->string('return_resolution', 20)->nullable();
            $table->timestamp('return_resolved_at')->nullable();
            $table->unsignedBigInteger('return_resolved_by')->nullable();
            $table->text('return_note')->nullable();

            // Alertas abertos (códigos) e os que já viraram notificação —
            // o segundo existe pra sineta não repetir o mesmo aviso a cada
            // 30 minutos.
            $table->json('flex_alerts')->nullable();
            $table->json('flex_alerts_notified')->nullable();
            // Os códigos que estavam abertos quando alguém resolveu. Um
            // alerta NOVO depois disso (reclamação aberta uma semana depois)
            // aparece de novo em vez de ficar escondido pela resolução velha.
            $table->json('flex_alerts_resolved')->nullable();
        });

        Schema::table('flex_pickup_receipts', function (Blueprint $table) {
            // Integridade: o hash dos bytes gravados no momento da entrega.
            // Se alguém questionar a foto daqui a um ano, dá pra provar que
            // o arquivo é o mesmo que chegou do celular naquele instante.
            $table->char('signature_sha256', 64)->nullable()->after('signature_path');
            $table->char('photo_sha256', 64)->nullable()->after('photo_path');
            $table->string('ip_address', 45)->nullable()->after('device');
            $table->string('user_agent', 255)->nullable()->after('ip_address');

            // Retenção como prova: recibo retido não tem as imagens apagadas
            // pelo koraflex:limpar-recibos.
            $table->timestamp('legal_hold_at')->nullable();
            $table->string('legal_hold_reason', 255)->nullable();
            $table->unsignedBigInteger('legal_hold_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flex_pickup_receipts', function (Blueprint $table) {
            $table->dropColumn([
                'signature_sha256', 'photo_sha256', 'ip_address', 'user_agent',
                'legal_hold_at', 'legal_hold_reason', 'legal_hold_by',
            ]);
        });

        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->dropColumn([
                'channel_status', 'channel_substatus', 'channel_shipped_at', 'channel_first_visit_at',
                'channel_delivered_at', 'channel_not_delivered_at', 'channel_returned_at',
                'channel_cancelled_at', 'channel_status_checked_at',
                'return_resolution', 'return_resolved_at', 'return_resolved_by', 'return_note',
                'flex_alerts', 'flex_alerts_notified', 'flex_alerts_resolved',
            ]);
        });
    }
};
