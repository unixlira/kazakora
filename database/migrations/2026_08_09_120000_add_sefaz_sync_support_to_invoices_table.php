<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-08-09: "buscar todas [as notas] no SEFAZ" — a
 * Distribuição DFe (NFeDistribuicaoService) traz notas que existem de
 * verdade na SEFAZ mas nunca tiveram uma linha local aqui (emitidas antes
 * deste sistema existir, ou em qualquer sessão/teste anterior que nunca
 * deixou registro — ver comentário em config/nfe.php sobre os números 1/2
 * "perdidos"). Uma nota assim não tem Order do Kazakora pra pendurar, então
 * order_id precisa virar opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();

            // 'pedido' = fluxo normal (automático ou emissão manual pra um
            // Order existente). 'sefaz' = importada pela sincronização de
            // Distribuição DFe, sem Order local correspondente.
            $table->string('origem')->default('pedido')->after('order_id');

            // Só preenchido pra origem='sefaz' — não tem Order/user pra
            // puxar nome/documento do destinatário, então guarda direto
            // aqui (vem do resumo/nota que a própria SEFAZ devolve).
            $table->string('destinatario_nome')->nullable()->after('origem');
            $table->string('destinatario_documento', 20)->nullable()->after('destinatario_nome');

            // NSU (Número Sequencial Único) do documento na Distribuição
            // DFe — guarda o maior já processado por chave pra nunca
            // reimportar o mesmo documento duas vezes numa sincronização
            // futura.
            $table->unsignedBigInteger('nsu')->nullable()->after('destinatario_documento');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['origem', 'destinatario_nome', 'destinatario_documento', 'nsu']);
            $table->foreignId('order_id')->nullable(false)->change();
        });
    }
};
