<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meu Perfil da Beauty Kora igual ao da KazaKora (pedido 2026-10-14): foto,
 * telefone, CPF e nascimento do cliente; vários endereços com apelido, quem
 * recebe, telefone e o principal. O endereço mais recente de cada cliente
 * (o único que o site usava até aqui) vira o principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beautykora_clientes', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('cpf', 14)->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('cpf');
            $table->string('avatar_path')->nullable()->after('birth_date');
        });

        Schema::table('beautykora_enderecos', function (Blueprint $table) {
            $table->string('apelido', 60)->nullable()->after('cliente_id');
            $table->string('destinatario', 191)->nullable()->after('apelido');
            $table->string('telefone', 20)->nullable()->after('destinatario');
            $table->boolean('principal')->default(false)->after('estado');
        });

        $ultimos = DB::table('beautykora_enderecos')->selectRaw('MAX(id) as id')->groupBy('cliente_id')->pluck('id');
        DB::table('beautykora_enderecos')->whereIn('id', $ultimos)->update(['principal' => true]);
    }

    public function down(): void
    {
        Schema::table('beautykora_enderecos', function (Blueprint $table) {
            $table->dropColumn(['apelido', 'destinatario', 'telefone', 'principal']);
        });

        Schema::table('beautykora_clientes', function (Blueprint $table) {
            $table->dropColumn(['phone', 'cpf', 'birth_date', 'avatar_path']);
        });
    }
};
