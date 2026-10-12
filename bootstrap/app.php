<?php

use App\Http\Middleware\AuthenticateKoraFlex;
use App\Http\Middleware\BloquearIndexacaoForaDoDominio;
use App\Http\Middleware\AuthenticatePrintAgent;
use App\Http\Middleware\EnsureApiPartnerIsActive;
use App\Http\Middleware\EnsureHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Http\Middleware\ExpireStaleSession;
use App\Http\Middleware\GuardarCupomDaUrl;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogApiRequest;
use App\Http\Middleware\PreventBrowserCaching;
use App\Http\Middleware\TrackSiteVisit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel defaults this to route('login'); the route was renamed to
        // 'entrar' as part of translating every route name to pt-BR.
        $middleware->redirectGuestsTo(fn () => route('entrar'));

        // O site passa pelo Cloudflare: sem isso, $request->ip() era sempre
        // o IP do Cloudflare (site_visits, cookies, contato, rate limit).
        // Confia só nas faixas oficiais (cloudflare.com/ips) e só no
        // X-Forwarded-For; quem chama a VPS direto não consegue forjar o IP.
        // Proto/Host ficam de fora de propósito (ver shopee.push_url).
        $middleware->trustProxies(
            at: [
                '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
                '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
                '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
                '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
                '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
                '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR,
        );

        $middleware->web(append: [
            HandleInertiaRequests::class,
            TrackSiteVisit::class,
            PreventBrowserCaching::class,
            ExpireStaleSession::class,
            GuardarCupomDaUrl::class,
            BloquearIndexacaoForaDoDominio::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'staff' => EnsureUserIsStaff::class,
            'permission' => EnsureHasPermission::class,
            'print.agent' => AuthenticatePrintAgent::class,
            'koraflex' => AuthenticateKoraFlex::class,
            'log.api' => LogApiRequest::class,
            // Sanctum 4.x parou de registrar esses alias sozinho (era
            // automático em versões antigas) — confirmado lendo o
            // SanctumServiceProvider da versão instalada (^4.3). Sem isso,
            // qualquer rota com `abilities:...` explodia com "Target class
            // [abilities] does not exist" em vez de simplesmente barrar
            // quem não tem a ability certa.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'api.partner.active' => EnsureApiPartnerIsActive::class,
        ]);

        // log.api precisa rodar POR FORA do auth pra registrar os 401/403
        // da API pública (ver LogApiRequest). Só listar ele primeiro na
        // rota não basta: o Laravel reordena os middlewares pela lista de
        // prioridade, e o SubstituteBindings do grupo 'api' puxava o
        // Authenticate pra frente do log — token inválido nunca entrava na
        // trilha de auditoria.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: LogApiRequest::class,
        );

        // The 'api' group has no session/CSRF middleware to begin with, but the
        // webhook is listed explicitly so it stays exempt even if it's ever
        // moved under the 'web' group.
        $middleware->validateCsrfTokens(except: [
            'api/mercadolivre/webhook',
            'api/stripe/webhook',
            'api/mercadopago/webhook',
            'api/shopee/webhook',
            'api/webhooks/whatsapp',
            'api/whatsapp/webhook',
            'api/bling/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
