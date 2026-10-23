<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Medição do checkout (pedido do Lira 2026-10-23): de 12 a 23/10, 41 de
     * 54 visitantes saíram da /finalizacao sem digitar nem o CEP e o site só
     * sabia que a página foi aberta. Aqui fica o que a pessoa fez na tela
     * (rolou, focou, preencheu, clicou Finalizar, saiu e quanto tempo ficou),
     * SEM o que ela digitou e sem IP. Leitura: `php artisan checkout:funil`.
     * Apagado aos 6 meses (privacidade:limpar).
     */
    public function up(): void
    {
        Schema::create('checkout_eventos', function (Blueprint $table) {
            $table->id();
            // Igual ao site_visits: cookie kazakora_visitor com o OK, senão "s-" + hash da sessão.
            $table->string('visitor_id', 36)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // Código aleatório de cada abertura da página (não fica no navegador).
            $table->string('pagina_id', 16)->nullable();
            $table->string('evento', 20);
            $table->string('campo', 20)->nullable();
            // Segundos ativos (saiu) ou % rolada (rolagem).
            $table->unsignedInteger('valor')->nullable();
            // Rolagem máxima no "saiu".
            $table->unsignedTinyInteger('rolagem')->nullable();
            $table->string('dispositivo', 12)->nullable();
            $table->string('origem', 20)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['evento', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_eventos');
    }
};
