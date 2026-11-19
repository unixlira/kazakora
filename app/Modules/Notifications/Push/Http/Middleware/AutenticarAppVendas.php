<?php

namespace App\Modules\Notifications\Push\Http\Middleware;

use App\Modules\Notifications\Push\TokensDoApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer do app de vendas (TokensDoApp): só admin, só o app do token. */
class AutenticarAppVendas
{
    public function handle(Request $request, Closure $next): Response
    {
        $app = (string) $request->route('app');
        $token = TokensDoApp::validar($request->bearerToken(), $app);

        if (! $token) {
            return response()->json(['message' => 'Entre de novo no app.'], 401);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->save();
        }
        $user = $token->tokenable;
        $request->attributes->set('app_vendas_token', $token);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
