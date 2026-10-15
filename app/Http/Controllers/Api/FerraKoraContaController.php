<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\FerraKora\Mail\NovaSenhaFerraKora;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraSessao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Conta do site FerraKora (pedido 2026-10-14). Dados separados por projeto:
 * a conta é do FerraKora (ferrakora_clientes), com e-mail, senha e link de
 * nova senha próprios; não é a conta da KazaKora. O site guarda o token e
 * manda como Bearer (middleware AuthenticateFerraKoraCliente).
 */
class FerraKoraContaController extends Controller
{
    /** Validade do link de nova senha. */
    public const MINUTOS_NOVA_SENHA = 60;

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
        if (FerraKoraCliente::query()->where('email', $email)->exists()) {
            return response()->json([
                'message' => 'Este e-mail já tem conta. Entre com a sua senha.',
                'errors' => ['email' => ['Este e-mail já tem conta. Entre com a sua senha.']],
            ], 422);
        }

        $cliente = FerraKoraCliente::query()->create([
            'name' => $dados['name'],
            'email' => $email,
            'password' => $dados['password'],
        ]);

        return $this->comSessao($cliente, $request, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $cliente = FerraKoraCliente::query()->where('email', mb_strtolower(trim($dados['email'])))->first();
        if (! $cliente || ! Hash::check($dados['password'], $cliente->password)) {
            return response()->json(['message' => 'E-mail ou senha incorretos.'], 422);
        }

        return $this->comSessao($cliente, $request);
    }

    /** Link de nova senha do FerraKora; a resposta é a mesma com ou sem conta. */
    public function esqueciSenha(Request $request): JsonResponse
    {
        $dados = $request->validate(['email' => ['required', 'email']]);

        $cliente = FerraKoraCliente::query()->where('email', mb_strtolower(trim($dados['email'])))->first();
        if ($cliente) {
            $token = Str::random(64);
            $cliente->forceFill([
                'reset_token_hash' => hash('sha256', $token),
                'reset_expira_em' => now()->addMinutes(self::MINUTOS_NOVA_SENHA),
            ])->save();

            $link = rtrim((string) config('services.ferrakora.url'), '/').'/nova-senha?'.http_build_query(['token' => $token, 'email' => $cliente->email]);
            rescue(fn () => Mail::to($cliente->email)->send(new NovaSenhaFerraKora($cliente, $link, self::MINUTOS_NOVA_SENHA)));
        }

        return response()->json(['message' => 'Se houver conta com este e-mail, enviamos o link para criar uma nova senha.']);
    }

    /** Troca a senha pelo link do e-mail e já entra; as outras sessões são encerradas. */
    public function novaSenha(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
        ]);

        $cliente = FerraKoraCliente::query()->where('email', mb_strtolower(trim($dados['email'])))->first();
        $valido = $cliente
            && $cliente->reset_token_hash
            && $cliente->reset_expira_em?->isFuture()
            && hash_equals($cliente->reset_token_hash, hash('sha256', $dados['token']));

        if (! $valido) {
            return response()->json(['message' => 'O link venceu ou já foi usado. Peça um novo em "Esqueci minha senha".'], 422);
        }

        $cliente->forceFill(['password' => $dados['password'], 'reset_token_hash' => null, 'reset_expira_em' => null])->save();
        $cliente->sessoes()->get()->each->encerrar();

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
