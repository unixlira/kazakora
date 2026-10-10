<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cupons completos (pedido 2026-10-10): regras de validade/uso no cupom,
 * disparos em lote (carrinho abandonado, clientes, épocas sazonais) e a
 * opção do cliente de não receber mais promoções.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('name')->nullable()->after('code');
            $table->decimal('min_order_value', 10, 2)->nullable()->after('discount_value');
            $table->unsignedInteger('max_uses')->nullable()->after('min_order_value');
            $table->boolean('one_per_customer')->default(false)->after('max_uses');
            $table->timestamp('starts_at')->nullable()->after('one_per_customer');
            $table->timestamp('expires_at')->nullable()->after('starts_at');
        });

        // Código sempre em maiúsculas: o cliente digita "volta10" e acha "VOLTA10".
        DB::table('coupons')->update(['code' => DB::raw('UPPER(code)')]);

        Schema::create('coupon_disparos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->string('publico', 40);
            $table->unsignedSmallInteger('dias')->nullable();
            $table->string('ocasiao', 40)->nullable();
            $table->string('assunto');
            $table->text('mensagem');
            $table->json('canais');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('enviados')->default(0);
            $table->unsignedInteger('falhas')->default(0);
            $table->string('status', 20)->default('pendente');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('recebe_promocoes')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('recebe_promocoes'));
        Schema::dropIfExists('coupon_disparos');
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn(['name', 'min_order_value', 'max_uses', 'one_per_customer', 'starts_at', 'expires_at']));
    }
};
