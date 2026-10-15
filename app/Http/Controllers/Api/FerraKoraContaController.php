<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\FerraKora\Mail\FerraKoraNovaSenha;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraSessao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Conta do site FerraKora (pedido 2026-10-14). Desde 2026-10-15 é separada da
 * KazaKora (tabela ferrakora_clientes): quem compra no checkout do FerraKora
 * ganha conta aqui, com senha sorteada, e cria a senha dele em "Esqueci minha
 * senha". O site guarda o token e manda como Bearer (AuthenticateFerraKoraCliente).
 */
class FerraKoraContaController extends Controller
{
    public const MINUTOS_DO_LINK = 60;

    private const MENSAGEM_COMPROU = 'Este e-mail já fez compra no FerraKora. Use "Esqueci minha senha" para criar a sua senha.';

    public function cadastro(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
        ]);

        $email = mb_strtolower(trim($dados['email']));
        $conta = FerraKoraCliente::query()->where('email', $email)->first();
        if ($conta) {
            $mensagem = $conta->senha_definida ? 'Já existe uma conta com este e-mail. Entre com a sua senha.' : self::MENSAGEM_COMPROU;

            return response()->json(['message' => $mensagem, 'errors' => ['email' => [$mensagem]]], 422);
        }

        $cliente = FerraKoraCliente::query()->create([...$dados, 'email' => $email, 'senha_definida' => true]);

        return $this->comSessao($cliente, $request, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $cliente = FerraKoraCliente::query()->where('email', mb_strtolower(trim($dados['email'])))->first();
        if ($cliente && ! $cliente->senha_definida) {
            return response()->json(['message' => self::MENSAGEM_COMPROU], 422);
        }
        if (! $cliente || ! Hash::check($dados['password'], $cliente->password)) {
            return response()->json(['message' => 'E-mail ou senha incorretos.'], 422);
        }

        return $this->comSessao($cliente, $request);
    }

    /**
     * Link de nova senha do FerraKora (vale MINUTOS_DO_LINK); a resposta é a
     * mesma com ou sem conta. O banco guarda só o hash do código.
     */
    public function esqueciSenha(Request $request): JsonResponse
    {
        $dados = $request->validate(['email' => ['required', 'email']]);
        $cliente = FerraKoraCliente::query()->where('email', mb_strtolower(trim($dados['email'])))->first();

        if ($cliente) {
            $token = Str::random(64);
            DB::table('ferrakora_senha_tokens')->updateOrInsert(
                ['email' => $cliente->email],
                ['token_hash' => hash('sha256', $token), 'created_at' => now()],
            );
            $link = rtrim((string) config('services.ferrakora.url'), '/').'/nova-senha?'.http_build_query(['token' => $token, 'email' => $cliente->email]);
            rescue(fn () => Mail::to($cliente->email)->send(new FerraKoraNovaSenha($cliente, $link)));
        }

        return response()->json(['message' => 'Se houver conta com este e-mail, enviamos o link para criar uma nova senha.']);
    }

    /** Troca a senha pelo link do e-mail, derruba as outras sessões e já entra. */
    public function novaSenha(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
        ]);

        $email = mb_strtolower(trim($dados['email']));
        $pedido = DB::table('ferrakora_senha_tokens')->where('email', $email)->first();
        $cliente = FerraKoraCliente::query()->where('email', $email)->first();

        $valido = $pedido && $cliente
            && hash_equals($pedido->token_hash, hash('sha256', $dados['token']))
            && now()->subMinutes(self::MINUTOS_DO_LINK)->lt($pedido->created_at);
        if (! $valido) {
            return response()->json(['message' => 'Este link venceu ou já foi usado. Peça um novo em "Esqueci minha senha".'], 422);
        }

        DB::table('ferrakora_senha_tokens')->where('email', $email)->delete();
        $cliente->forceFill(['password' => $dados['password'], 'senha_definida' => true])->save();
        FerraKoraSessao::encerrarTodas($cliente);

        return $this->comSessao($cliente, $request);
    }

    public function eu(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->paraSite()]);
    }

    public function atualizar(Request $request): JsonResponse
    {
        $dados = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $cliente = $request->user();
        $cliente->update($dados);

        return response()->json(['user' => $cliente->paraSite()]);
    }

    public function sair(Request $request): JsonResponse
    {
        $request->attributes->get('ferrakora_sessao')?->encerrar();

        return response()->json(['ok' => true]);
    }

    private function comSessao(FerraKoraCliente $cliente, Request $request, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => FerraKoraSessao::abrir($cliente, $request),
            'expira_em' => now()->addDays(FerraKoraSessao::DIAS)->toIso8601String(),
            'user' => $cliente->paraSite(),
        ], $status);
    }
}
