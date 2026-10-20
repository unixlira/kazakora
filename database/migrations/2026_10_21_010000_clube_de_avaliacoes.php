<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clube de Avaliações (pedido 2026-10-20): o cliente pode ocultar o nome na
 * avaliação, o cupom pode ser pessoal (só a conta dona usa) e cada nível
 * alcançado gera um cupom uma única vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reviews', 'ocultar_nome')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->boolean('ocultar_nome')->default(false)->after('comment');
            });
        }

        if (! Schema::hasColumn('coupons', 'user_id')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('code')->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasTable('recompensas_avaliacao')) {
            Schema::create('recompensas_avaliacao', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('nivel', 20);
                $table->unsignedInteger('pontos');
                $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'nivel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recompensas_avaliacao');

        if (Schema::hasColumn('coupons', 'user_id')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }

        if (Schema::hasColumn('reviews', 'ocultar_nome')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->dropColumn('ocultar_nome');
            });
        }
    }
};
