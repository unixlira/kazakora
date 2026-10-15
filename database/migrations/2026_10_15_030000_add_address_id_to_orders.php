<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liga o pedido ao endereço salvo que o cliente usou (pedido 2026-10-14:
 * "Meu Perfil" não deixa excluir endereço que já tem pedido). O pedido
 * continua com a cópia do endereço em shipping_*; o address_id só diz de
 * qual endereço salvo ele veio. Pedidos antigos são ligados pelo mesmo
 * usuário + CEP + número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('address_id')->nullable()->after('user_id')->constrained('addresses')->nullOnDelete();
        });

        $digitos = fn (?string $valor) => preg_replace('/\D/', '', (string) $valor);

        DB::table('addresses')->orderBy('id')->each(function ($endereco) use ($digitos) {
            DB::table('orders')
                ->where('user_id', $endereco->user_id)
                ->whereNull('address_id')
                ->where('shipping_number', $endereco->number)
                ->get(['id', 'shipping_zip'])
                ->filter(fn ($pedido) => $digitos($pedido->shipping_zip) === $digitos($endereco->zip))
                ->each(fn ($pedido) => DB::table('orders')->where('id', $pedido->id)->update(['address_id' => $endereco->id]));
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('address_id');
        });
    }
};
