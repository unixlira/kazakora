<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quadro da página do produto controlado pela promoção (pedido do Lira
 * 2026-10-21): `condicoes` (ex.: "Pedidos realizados e pagos até 31/10/2026
 * participam automaticamente.") e o botão que leva à página pública
 * /promocoes/{slug} (`botao_ativo`, `botao_texto`; texto null = padrão).
 *
 * No Airfryer as condições estavam no 1º parágrafo do sorteio (Entregáveis):
 * o texto é copiado para a promoção, que passa a mandar no quadro. O sorteio
 * fica como estava (vale de novo se as condições forem apagadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            $table->text('condicoes')->nullable()->after('descricao');
            $table->boolean('botao_ativo')->default(false)->after('imagem');
            $table->string('botao_texto', 60)->nullable()->after('botao_ativo');
        });

        $sorteio = DB::table('sorteios')->where('ativo', true)->whereNull('product_id')->orderBy('id')->first();
        if ($sorteio !== null) {
            $texto = trim($sorteio->paragrafo_1."\n".($sorteio->paragrafo_2 ?? ''));
            DB::table('promocoes')->where('slug', 'airfryer')->whereNull('condicoes')->update(['condicoes' => $texto]);
        }
    }

    public function down(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            $table->dropColumn(['condicoes', 'botao_ativo', 'botao_texto']);
        });
    }
};
