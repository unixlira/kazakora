<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trilha de auditoria da API pública — ver a migração de api_request_logs
 * pro porquê. Roda em TODA rota /api/v1/*, antes e depois de qualquer
 * checagem de auth/ability (registra 401/403 também — tentativa de acesso
 * com token errado é informação de segurança relevante, não só sucesso).
 * A gravação em si nunca pode derrubar o request real — envolvida em
 * try/catch silencioso, o log é auxiliar, não o propósito da chamada.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            // $request->user('sanctum') em vez de user() puro — o guard
            // padrão da aplicação é 'web' (ver config/auth.php), e nada
            // nas rotas /api/v1 troca o guard default global, só o
            // middleware auth:sanctum,jwt_partner autentica especificamente
            // contra esses guards. Checa os dois — parceiro logado via
            // JWT (senha) não aparece em user('sanctum'), ver
            // AppServiceProvider::boot().
            $partner = $request->user('sanctum') ?? $request->user('jwt_partner');

            ApiRequestLog::create([
                'api_partner_id' => $partner?->id,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status_code' => $response->getStatusCode(),
                'ip' => (string) $request->ip(),
            ]);

            // Timestamp("touch") sem disparar updated_at/observers — só
            // pra "último uso visto" aparecer na tela de gestão de
            // parceiros, não é um dado crítico que precise de mais rigor
            // que isso.
            $partner?->forceFill(['last_used_at' => now()])->saveQuietly();
        } catch (\Throwable) {
            // Silencioso de propósito — ver docblock da classe.
        }

        return $response;
    }
}
