<?php

namespace App\Providers;

use App\Models\ApiPartner;
use App\Support\Jwt\ApiPartnerJwt;
use App\Support\Jwt\JwtAccessToken;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Route::resourceVerbs([
            'create' => 'criar',
            'edit' => 'editar',
        ]);

        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => url(route('senha.redefinir', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false)));

        // Pedido explícito 2026-08-09: teto absoluto de 8h de sessão (ver
        // App\Http\Middleware\ExpireStaleSession) — marca o instante do
        // login. O evento Login nativo do Laravel dispara em login
        // normal, "lembrar-me" (recaller cookie) e Auth::login() manual
        // (auto-login do checkout convidado), então um único listener
        // aqui cobre os 3 pontos de entrada sem precisar tocar em cada
        // controller.
        Event::listen(function (Login $event): void {
            session(['login_at' => now()->timestamp]);
        });

        // Pedido explícito 2026-08-21 (API pública pra parceiros
        // externos): limite POR PARCEIRO, não global — cada ApiPartner
        // pode ter um teto diferente (rate_limit_per_minute, default 60),
        // pensado pra parceiro maior negociar um limite mais alto sem
        // precisar mexer em código. Sem token válido (nunca deveria
        // chegar aqui — auth:sanctum,jwt_partner roda antes — mas
        // defensivo mesmo assim), cai pro limite mínimo por IP.
        //
        // Checa os DOIS guards (sanctum e jwt_partner, ver abaixo) — um
        // parceiro autenticado via JWT não aparece em user('sanctum').
        RateLimiter::for('api', function (Request $request) {
            $partner = $request->user('sanctum') ?? $request->user('jwt_partner');

            return Limit::perMinute($partner?->rate_limit_per_minute ?? 30)
                ->by($partner?->id ? "api-partner:{$partner->id}" : 'api-ip:'.$request->ip());
        });

        // Guard 'jwt_partner' (pedido explícito 2026-08-22): login
        // self-service usuário/senha -> JWT, alternativa ao token estático
        // do Sanctum emitido só pelo admin. Auth::viaRequest() é o jeito
        // suportado pelo framework de registrar um driver de auth ad hoc
        // sem escrever uma classe Guard completa. withAccessToken() é o
        // mesmo mecanismo que o próprio Sanctum documenta pra autenticar
        // via um token que não é dele (ver App\Support\Jwt\JwtAccessToken)
        // — é isso que faz o middleware `abilities:`/`ability:` já
        // existente funcionar sem duplicar checagem nenhuma.
        Auth::viaRequest('jwt_partner', function (Request $request) {
            $token = $request->bearerToken();

            if (! $token) {
                return null;
            }

            $claims = ApiPartnerJwt::decode($token);

            if (! $claims) {
                return null;
            }

            $partner = ApiPartner::find($claims['sub']);

            if (! $partner) {
                return null;
            }

            return $partner->withAccessToken(new JwtAccessToken($claims['abilities']));
        });
    }
}
