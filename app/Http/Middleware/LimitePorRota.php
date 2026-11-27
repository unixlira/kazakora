<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` com um contador POR ROTA (incidente de 27/11/2026, cliente
 * Bernardete): no Laravel, todo `throttle:N,M` sem prefixo usa a mesma
 * chave (usuário ou IP), então as rotas dividiam um contador só. O checkout
 * salva cada campo preenchido em /finalizacao/lead-campo (throttle:60,1);
 * quem preenchia rápido somava mais de 10 batidas num minuto e o "Finalizar"
 * (/finalizacao/pagamento, throttle:10,1) voltava 429 sem nenhuma tentativa
 * de pagamento. Aconteceu em 14, 19, 21, 26 e 27/11.
 *
 * Agora a chave leva o nome da rota (ou o método + caminho): cada limite
 * conta só as batidas dele, como sempre se imaginou ao escrever as rotas.
 * Limites nomeados (`throttle:api`) seguem pelo RateLimiter, sem mudança.
 */
class LimitePorRota extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();
        $rota = $route ? ($route->getName() ?: $request->method().' '.$route->uri()) : '';

        return sha1($rota.'|'.parent::resolveRequestSignature($request));
    }
}
