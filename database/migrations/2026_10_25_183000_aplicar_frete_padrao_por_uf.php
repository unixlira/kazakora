<?php

use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use Illuminate\Database\Migrations\Migration;

/**
 * Frete padrão por estado (pedido do Lira em 2026-10-25): o ES inteiro com o
 * PAC e o SEDEX de Afonso Cláudio. Em 25/10 uma recotação em lote trocou parte
 * do ES pelo valor dos Correios; aqui a cópia volta a valer. Sem tabela
 * cotada (banco novo) não faz nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TabelaFreteLocalidades::class)->aplicarPadroes();
    }

    public function down(): void
    {
        // A cotação dos Correios de cada localidade não é guardada: nada a desfazer.
    }
};
