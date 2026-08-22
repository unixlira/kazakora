<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O Sanctum sozinho só valida "esse token existe e não expirou" — não sabe
 * nada sobre `is_active` do ApiPartner dono dele. Sem isso, desativar um
 * parceiro na tela de gestão (Admin\ApiPartnerController::update()) não
 * bloqueava nada de verdade: os tokens já emitidos continuavam
 * funcionando até serem revogados um por um manualmente. Roda DEPOIS de
 * auth:sanctum (precisa do parceiro já resolvido).
 */
class EnsureApiPartnerIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $partner = $request->user('sanctum');

        abort_if($partner && ! $partner->is_active, 403, 'Este parceiro está desativado.');

        return $next($request);
    }
}
