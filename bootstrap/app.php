<?php

use App\Http\Middleware\AuthenticatePrintAgent;
use App\Http\Middleware\EnsureApiPartnerIsActive;
use App\Http\Middleware\EnsureHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Http\Middleware\ExpireStaleSession;
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

        $middleware->web(append: [
            HandleInertiaRequests::class,
            TrackSiteVisit::class,
            PreventBrowserCaching::class,
            ExpireStaleSession::class,
        ]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'staff' => EnsureUserIsStaff::class,
            'permission' => EnsureHasPermission::class,
            'print.agent' => AuthenticatePrintAgent::class,
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
