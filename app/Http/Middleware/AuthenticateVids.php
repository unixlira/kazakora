<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vids (Máquina de Criativos, 2026-11-20) manda os vídeos prontos para a
 * Validação de criativos. Sem sessão: token fixo (VIDS_TOKEN, Bearer), só
 * para as rotas /api/vids/*. O envio entra em nome do usuário VIDS_USUARIO_ID,
 * para reaproveitar o envio em partes da tela (que separa a pasta por pessoa).
 */
class AuthenticateVids
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.vids.token');
        $usuario = User::query()->find((int) config('services.vids.usuario_id'));

        abort_if($token === '' || ! $usuario, 503, 'Vids não configurado no servidor.');
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        Auth::setUser($usuario);

        return $next($request);
    }
}
