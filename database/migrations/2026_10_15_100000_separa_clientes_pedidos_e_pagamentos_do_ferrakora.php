<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * FerraKora separado da KazaKora (pedido do Lira 2026-10-15): o cliente do
 * FerraKora não vira mais User da loja. Conta em ferrakora_clientes (que já
 * existia parada desde o #274), sessão do site ligada a ela, pedido ligado
 * por orders.ferrakora_cliente_id (user_id nulo) e pagamento marcado com o
 * site em payments.site_origem.
 *
 * Os pedidos FerraKora que já existem (homolog) passam para a conta própria,
 * criada sem senha: o cliente usa "Esqueci minha senha". As contas da
 * KazaKora que o checkout antigo criou ficam como estão (nada é apagado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ferrakora_clientes', function (Blueprint $table) {
            $table->string('cpf', 11)->nullable()->unique()->after('email');
            $table->string('phone', 20)->nullable()->after('cpf');
            // Conta criada pelo checkout: senha sorteada até o cliente criar a dele.
            $table->boolean('senha_definida')->default(true)->after('password');
        });

        // Sessões abertas eram de contas da KazaKora: caem todas (login de novo).
        DB::table('ferrakora_sessoes')->delete();
        Schema::table('ferrakora_sessoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('ferrakora_sessoes', function (Blueprint $table) {
            $table->foreignId('cliente_id')->after('id')->constrained('ferrakora_clientes')->cascadeOnDelete();
        });

        Schema::create('ferrakora_senha_tokens', function (Blueprint $table) {
            $table->string('email', 191)->primary();
            $table->char('token_hash', 64);
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('ferrakora_cliente_id')->nullable()->after('user_id')->constrained('ferrakora_clientes')->nullOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('site_origem', 30)->nullable()->after('order_id')->index();
        });

        foreach (DB::table('orders')->whereNotNull('site_origem')->distinct()->pluck('site_origem') as $site) {
            DB::table('payments')
                ->whereIn('order_id', DB::table('orders')->select('id')->where('site_origem', $site))
                ->update(['site_origem' => $site]);
        }

        $this->moverPedidosAntigos();
    }

    private function moverPedidosAntigos(): void
    {
        $pedidos = DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->where('orders.site_origem', 'ferrakora')
            ->whereNull('orders.ferrakora_cliente_id')
            ->orderBy('orders.id')
            ->get(['orders.id', 'orders.shipping_name', 'orders.shipping_phone', 'orders.buyer_document', 'users.name', 'users.email', 'users.cpf', 'users.phone']);

        foreach ($pedidos as $pedido) {
            $email = mb_strtolower(trim((string) $pedido->email));
            if ($email === '') {
                continue;
            }

            $cpf = preg_replace('/\D/', '', (string) ($pedido->buyer_document ?: $pedido->cpf)) ?: null;
            $clienteId = DB::table('ferrakora_clientes')->where('email', $email)->value('id');
            if (! $clienteId) {
                $cpfLivre = $cpf && ! DB::table('ferrakora_clientes')->where('cpf', $cpf)->exists();
                $clienteId = DB::table('ferrakora_clientes')->insertGetId([
                    'name' => $pedido->name ?: $pedido->shipping_name,
                    'email' => $email,
                    'cpf' => $cpfLivre ? $cpf : null,
                    'phone' => $pedido->phone ?: $pedido->shipping_phone,
                    'password' => Hash::make(Str::random(40)),
                    'senha_definida' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('orders')->where('id', $pedido->id)->update([
                'ferrakora_cliente_id' => $clienteId,
                'user_id' => null,
                'address_id' => null,
                'buyer_document' => $cpf,
                'shipping_email' => $email,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['site_origem']);
            $table->dropColumn('site_origem');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ferrakora_cliente_id');
        });

        Schema::dropIfExists('ferrakora_senha_tokens');

        DB::table('ferrakora_sessoes')->delete();
        Schema::table('ferrakora_sessoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_id');
        });
        Schema::table('ferrakora_sessoes', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('ferrakora_clientes', function (Blueprint $table) {
            $table->dropUnique(['cpf']);
            $table->dropColumn(['cpf', 'phone', 'senha_definida']);
        });
    }
};
