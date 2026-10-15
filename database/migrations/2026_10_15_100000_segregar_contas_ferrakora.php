<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dados separados por projeto (pedido do Lira 2026-10-14): a conta do site
 * FerraKora deixa de ser a conta da KazaKora.
 *
 * - ferrakora_clientes volta a ser a conta do site (e-mail e senha próprios)
 *   e ganha o link de nova senha próprio (reset_token_hash/reset_expira_em)
 *   e o Meu Perfil (telefone, CPF, nascimento, foto e ferrakora_enderecos),
 *   no mesmo modelo do Beauty Kora.
 * - ferrakora_sessoes passa a ser de ferrakora_clientes. As sessões abertas
 *   com a conta da KazaKora são encerradas (o cliente entra de novo); quem já
 *   tinha entrado no site leva o e-mail, o nome e a mesma senha para a conta
 *   nova, com os dados pessoais e os endereços, para não perder o acesso.
 * - users.site_origem diz de qual projeto veio o cadastro do comprador
 *   (nulo = KazaKora). O checkout do FerraKora e do Beauty Kora continua
 *   gravando o comprador em users (nota fiscal, expedição e cupom precisam),
 *   mas marcado com o site. Os que já existem são marcados quando todos os
 *   pedidos deles vieram do mesmo site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('site_origem', 30)->nullable()->after('role')->index();
        });

        DB::table('orders')
            ->whereNotNull('user_id')
            ->selectRaw('user_id, MIN(COALESCE(site_origem, \'\')) as menor, MAX(COALESCE(site_origem, \'\')) as maior')
            ->groupBy('user_id')
            ->get()
            ->filter(fn ($linha) => $linha->menor !== '' && $linha->menor === $linha->maior)
            ->each(fn ($linha) => DB::table('users')->where('id', $linha->user_id)->whereNull('site_origem')->update(['site_origem' => $linha->menor]));

        Schema::table('ferrakora_clientes', function (Blueprint $table) {
            $table->char('reset_token_hash', 64)->nullable()->after('password');
            $table->timestamp('reset_expira_em')->nullable()->after('reset_token_hash');
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('cpf', 14)->nullable()->after('phone');
            $table->date('birth_date')->nullable()->after('cpf');
            $table->string('avatar_path')->nullable()->after('birth_date');
        });

        Schema::create('ferrakora_enderecos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('ferrakora_clientes')->cascadeOnDelete();
            $table->string('apelido', 60)->nullable();
            $table->string('destinatario', 191)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('cep', 9);
            $table->string('logradouro');
            $table->string('numero', 20);
            $table->string('complemento')->nullable();
            $table->string('bairro');
            $table->string('cidade');
            $table->string('estado', 2);
            $table->boolean('principal')->default(false);
            $table->timestamps();
        });

        $agora = now();
        DB::table('users')
            ->whereIn('id', DB::table('ferrakora_sessoes')->select('user_id'))
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'email', 'password', 'phone', 'cpf', 'birth_date', 'avatar_path'])
            ->each(function ($user) use ($agora) {
                $email = mb_strtolower(trim($user->email));
                if (DB::table('ferrakora_clientes')->where('email', $email)->exists()) {
                    return;
                }
                $clienteId = DB::table('ferrakora_clientes')->insertGetId([
                    'name' => mb_substr((string) $user->name, 0, 120),
                    'email' => $email,
                    'password' => $user->password,
                    'phone' => $user->phone,
                    'cpf' => $user->cpf,
                    'birth_date' => $user->birth_date,
                    'avatar_path' => $user->avatar_path,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
                DB::table('addresses')->where('user_id', $user->id)->orderBy('id')->get()
                    ->each(fn ($endereco) => DB::table('ferrakora_enderecos')->insert([
                        'cliente_id' => $clienteId,
                        'apelido' => $endereco->label ?? null,
                        'destinatario' => $endereco->recipient_name ?? null,
                        'telefone' => $endereco->phone ?? null,
                        'cep' => $endereco->zip,
                        'logradouro' => $endereco->street,
                        'numero' => $endereco->number,
                        'complemento' => $endereco->complement,
                        'bairro' => $endereco->neighborhood,
                        'cidade' => $endereco->city,
                        'estado' => $endereco->state,
                        'principal' => (bool) $endereco->is_default,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ]));
                // Só entrou no site FerraKora e nunca comprou em outro lugar: o cadastro é do FerraKora.
                if (! DB::table('orders')->where('user_id', $user->id)->exists()) {
                    DB::table('users')->where('id', $user->id)->whereNull('site_origem')->update(['site_origem' => 'ferrakora']);
                }
            });

        DB::table('ferrakora_sessoes')->delete();
        Schema::table('ferrakora_sessoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('ferrakora_sessoes', function (Blueprint $table) {
            $table->foreignId('cliente_id')->after('id')->constrained('ferrakora_clientes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('ferrakora_sessoes')->delete();
        Schema::table('ferrakora_sessoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_id');
        });
        Schema::table('ferrakora_sessoes', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::dropIfExists('ferrakora_enderecos');
        Schema::table('ferrakora_clientes', function (Blueprint $table) {
            $table->dropColumn(['reset_token_hash', 'reset_expira_em', 'phone', 'cpf', 'birth_date', 'avatar_path']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['site_origem']);
            $table->dropColumn('site_origem');
        });
    }
};
