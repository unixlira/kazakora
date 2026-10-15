<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\FerraKora\Models\FerraKoraSessao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * Conta do site FerraKora (pedido 2026-10-14): o cliente entra com a MESMA
 * conta da KazaKora (tabela users, banco compartilhado), então quem comprou no
 * checkout do FerraKora já tem login (senha temporária no e-mail de boas-vindas).
 * O site guarda o token e manda como Bearer (middleware AuthenticateFerraKoraCliente).
 */
class FerraKoraContaController extends Controller
{
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
        if (User::emailEmUso($email)) {
            return response()->json([
                'message' => 'Este e-mail já tem conta na KazaKora. Entre com a mesma senha.',
                'errors' => ['email' => ['Este e-mail já tem conta na KazaKora. Entre com a mesma senha.']],
            ], 422);
        }

        $user = User::query()->create([
            'name' => $dados['name'],
            'email' => $email,
            'password' => $dados['password'],
            'role' => User::ROLE_CUSTOMER,
        ]);

        return $this->comSessao($user, $request, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', trim($dados['email']))->first();
        if (! $user || ! Hash::check($dados['password'], $user->password)) {
            return response()->json(['message' => 'E-mail ou senha incorretos.'], 422);
        }

        return $this->comSessao($user, $request);
    }

    /** Link de nova senha da KazaKora; a resposta é a mesma com ou sem conta. */
    public function esqueciSenha(Request $request): JsonResponse
    {
        $dados = $request->validate(['email' => ['required', 'email']]);

        rescue(fn () => Password::sendResetLink(['email' => trim($dados['email'])]));

        return response()->json(['message' => 'Se houver conta com este e-mail, enviamos o link para criar uma nova senha.']);
    }

    public function eu(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->paraSite($request->user())]);
    }

    public function atualizar(Request $request): JsonResponse
    {
        $dados = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $user = $request->user();
        $user->update($dados);

        return response()->json(['user' => $this->paraSite($user)]);
    }

    public function sair(Request $request): JsonResponse
    {
        $request->attributes->get('ferrakora_sessao')?->encerrar();

        return response()->json(['ok' => true]);
    }

    private function comSessao(User $user, Request $request, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => FerraKoraSessao::abrir($user, $request),
            'expira_em' => now()->addDays(FerraKoraSessao::DIAS)->toIso8601String(),
            'user' => $this->paraSite($user),
        ], $status);
    }

    /** Formato que o site espera (sem CPF, telefone nem endereço). */
    public static function paraSite(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created' => $user->created_at?->toIso8601String(),
        ];
    }
}
