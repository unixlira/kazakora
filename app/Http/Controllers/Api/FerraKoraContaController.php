<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Conta do site FerraKora (pedido 2026-10-14): cadastro, login e perfil do
 * menu de conta do topo. O site guarda o token no navegador e manda como
 * Bearer (middleware AuthenticateFerraKoraCliente).
 */
class FerraKoraContaController extends Controller
{
    public function cadastro(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', Rule::unique('ferrakora_clientes', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ], [
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
        ]);

        $cliente = FerraKoraCliente::query()->create([...$dados, 'email' => mb_strtolower($dados['email'])]);

        return $this->comToken($cliente, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $cliente = FerraKoraCliente::query()->where('email', mb_strtolower($dados['email']))->first();
        if (! $cliente || ! Hash::check($dados['password'], $cliente->password)) {
            return response()->json(['message' => 'E-mail ou senha incorretos.'], 422);
        }

        return $this->comToken($cliente);
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
        $request->attributes->get('ferrakora_token')?->delete();

        return response()->json(['ok' => true]);
    }

    private function comToken(FerraKoraCliente $cliente, int $status = 200): JsonResponse
    {
        $token = $cliente->createToken('ferrakora-site', ['ferrakora:cliente'], now()->addDays(30));

        return response()->json(['token' => $token->plainTextToken, 'user' => $cliente->paraSite()], $status);
    }
}
