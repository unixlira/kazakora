<?php

use App\Modules\Catalog\Support\PromocoesDoProduto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sorteio da AirFryer fora do ar (pedido do Lira 2026-10-21): ainda falta a
 * autorização da Caixa. Sobe desligado; os livros digitais seguem ligados.
 * Para religar: Vender › Promoções › Airfryer (chave da página do produto)
 * e Entregáveis › Sorteios (ativo).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Os testes contam com o sorteio semeado ligado (criar_entregaveis).
        if (app()->runningUnitTests()) {
            return;
        }

        DB::table('promocoes')->where('slug', 'airfryer')->update(['ativa' => false, 'na_pagina_produto' => false, 'updated_at' => now()]);
        DB::table('sorteios')->update(['ativo' => false, 'updated_at' => now()]);
        PromocoesDoProduto::limparCache();
    }

    public function down(): void
    {
        // Religar é decisão do Lira, pelo admin.
    }
};
