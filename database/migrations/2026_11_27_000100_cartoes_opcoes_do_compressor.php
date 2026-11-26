<?php

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Liga as opções em cartões no Compressor 4 em 1 (pedido do Lira 26/11/2026: "o formato da
 * variação igual ao do livro"), com o conteúdo de database/cartoes-opcoes/compressor-4-em-1.json.
 * Só onde o produto existe com o slug certo (produção); nos outros bancos não faz nada.
 * Desfazer: php artisan loja:cartoes-opcoes compressor-4-em-1 --desligar
 */
return new class extends Migration
{
    private const SLUG = 'bomba-de-ar-portatil-compressor-inflator-4-em-1-powerbank-calibrador';

    public function up(): void
    {
        if (Product::query()->whereKey(413)->where('slug', self::SLUG)->exists()) {
            Artisan::call('loja:cartoes-opcoes', ['nome' => 'compressor-4-em-1', '--aplicar' => true]);
        }
    }

    public function down(): void
    {
        Product::query()->whereKey(413)->where('slug', self::SLUG)->update(['cartoes_opcoes' => null]);
    }
};
