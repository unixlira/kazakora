<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Brinde físico da oferta (pedido do Lira em 21/11/2026): o que vai na caixa
// de graça junto com o produto (ex.: Lousa LED do Livro Falante). Sai numa
// segunda NF-e, de bonificação (CFOP 5910/6910), depois da nota de venda.
// {"nome": "...", "quantidade": 1, "ncm": "95030099", "origem": 2}
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('brinde_fisico')->nullable()->after('tags');
        });
        // Quando o cliente recebeu o e-mail com as duas notas (uma vez só).
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('bonus_email_enviado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('brinde_fisico');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('bonus_email_enviado_em');
        });
    }
};
