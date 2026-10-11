<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Support\Jwt\ApiPartnerJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Login self-service de parceiro de API (usuário/senha -> JWT) — pedido
 * explícito 2026-08-22, alternativa ao token estático emitido só pelo
 * admin (ver Admin\ApiPartnerController::issueToken()). Rota PÚBLICA, fora
 * do grupo auth:sanctum,jwt_partner (ver routes/api_v1.php) — o próprio
 * propósito dela é autenticar quem ainda não tem token.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'usuario' => ['required', 'string'],
            'senha' => ['required', 'string'],
        ]);

        // slug é o "usuário" — não existe coluna separada pra isso, ver a
        // migração que adicionou password. Resposta genérica em qualquer
        // motivo de falha (parceiro inexistente, sem senha configurada,
        // senha errada, parceiro inativo) — não dá pra um atacante
        // diferenciar "usuário não existe" de "senha errada".
        $partner = ApiPartner::query()->where('slug', $validated['usuario'])->first();

        if (! $partner || ! $partner->password || ! Hash::check($validated['senha'], $partner->password) || ! $partner->is_active) {
            return response()->json(['message' => 'Usuário ou senha inválidos.'], 401);
        }

        $abilities = $partner->allowedAbilities();

        return response()->json([
            'token' => ApiPartnerJwt::issue($partner->id, $abilities),
            'token_type' => 'Bearer',
            'expires_in' => ApiPartnerJwt::TTL_SECONDS,
            'abilities' => $abilities,
        ]);
    }
}
