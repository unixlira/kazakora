<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clique sem ponteiro no mapa de cliques (2026-11-20).
 *
 * O script antigo mandava x = 0 e y = 0 quando o clique não tinha
 * coordenada (Enter no teclado ou el.click() de programa): 80 dos 108
 * cliques do Jarvis na semana, todos do robô de revisão do Meta abrindo
 * cada anúncio novo e "clicando" em todos os links 15,7 s depois de abrir.
 * Agora: origem 't' (teclado) ou 'p' (programa/robô) com o centro do alvo,
 * null = mouse ou toque. Os 0,0 antigos viram posição desconhecida (null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('infoproduto_eventos', function (Blueprint $table) {
            $table->char('origem', 1)->nullable()->after('nome');
        });

        DB::table('infoproduto_eventos')->where('tipo', 'clk')->where('x', 0)->where('y', 0)
            ->update(['x' => null, 'y' => null]);
    }

    public function down(): void
    {
        Schema::table('infoproduto_eventos', function (Blueprint $table) {
            $table->dropColumn('origem');
        });
    }
};
