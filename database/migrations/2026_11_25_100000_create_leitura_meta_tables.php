<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leitura das campanhas (pedido do Lira em 25/11/2026).
 *
 * leitura_meta_ciclos: início do ciclo de teste, por campanha (id da Meta),
 * por produto ou por pedaço do nome da campanha. O período "Desde o início
 * do ciclo" e a regra "gastou X sem vender" contam a partir daqui.
 *
 * leitura_meta_cpa: CPA alvo e máximo digitados na tela, por produto, no
 * lugar do calculado (CpaDoProduto). Vazio = usa o calculado.
 *
 * Já grava o ciclo do travesseiro: campanhas da KazaKora com
 * "TRAVESSEIRO" no nome, desde 25/11/2026 16:41 (horário de Brasília).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leitura_meta_ciclos')) {
            Schema::create('leitura_meta_ciclos', function (Blueprint $table) {
                $table->id();
                $table->string('loja', 20);
                $table->string('campanha_id', 40)->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('nome_contem', 120)->nullable();
                $table->dateTime('inicio');
                $table->timestamps();
                $table->index(['loja', 'campanha_id']);
            });

            DB::table('leitura_meta_ciclos')->insert([
                'loja' => 'kazakora',
                'nome_contem' => 'TRAVESSEIRO',
                'inicio' => '2026-11-25 16:41:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('leitura_meta_cpa')) {
            Schema::create('leitura_meta_cpa', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->unique();
                $table->decimal('cpa_alvo', 10, 2)->nullable();
                $table->decimal('cpa_maximo', 10, 2)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leitura_meta_cpa');
        Schema::dropIfExists('leitura_meta_ciclos');
    }
};
