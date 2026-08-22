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
 * auth:sanctum,jwt_partner (precisa do parceiro já resolvido, ver
 * AppServiceProvider::boot() pro guard jwt_partner).
 */
class EnsureApiPartnerIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Checa os dois guards — um parceiro logado via JWT (senha) não
        // aparece em user('sanctum'), só em user('jwt_partner').
        $partner = $request->user('sanctum') ?? $request->user('jwt_partner');

        abort_if($partner && ! $partner->is_active, 403, 'Este parceiro está desativado.');

        return $next($request);
    }
}
