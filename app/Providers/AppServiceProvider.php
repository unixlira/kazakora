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
        // Uma por requisição: guarda o destino e a tabela de frete dela.
        $this->app->scoped(\App\Modules\Checkout\Support\FreteEmbutido::class);
        // Web Push dos apps de vendas: chaves VAPID do .env (2026-11-19).
        $this->app->bind(\App\Modules\Notifications\Push\WebPush::class, fn () => \App\Modules\Notifications\Push\WebPush::daConfig());
        // Trava do frete por visitante (2026-10-24): memória da requisição.
        $this->app->scoped(\App\Modules\Checkout\Support\TravaDeFrete::class);
        // Site que está vendendo (Ofertas Relâmpago por empresa, 2026-10-14).
        $this->app->scoped(\App\Support\SiteAtual::class);
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
        // (cadastro, passagem do checkout seguro), então um único listener
        // aqui cobre os pontos de entrada sem precisar tocar em cada
        // controller. O checkout de convidado não loga mais (2026-10-24).
        Event::listen(function (Login $event): void {
            session(['login_at' => now()->timestamp]);
            // Modal "Avalie sua compra" (correção 2026-11-21): liga em todo
            // login de cliente, não só no formulário (lembrar-me, cadastro, checkout).
            if ($event->guard === 'web' && ! ($event->user instanceof \App\Models\User && $event->user->isStaff())) {
                \App\Modules\Catalog\Support\ModalAvaliacao::ativar(session()->driver());
            }
            // Histórico do cliente (26/11/2026): cada entrada na conta e, com o
            // aceite de cookies, as páginas que o navegador abriu antes de entrar.
            if ($event->guard === 'web' && $event->user instanceof \App\Models\User) {
                $caminho = request()->path();
                \App\Modules\Clientes\Support\HistoricoDoCliente::kazakora($event->user, 'login', array_filter([
                    'como' => match (true) {
                        ! request()->isMethod('post') => 'lembrar-me',
                        str_starts_with($caminho, 'entrar/codigo') => 'código por e-mail',
                        $caminho === 'cadastro' => 'cadastro',
                        $caminho === 'entrar' => 'senha',
                        default => 'outro',
                    },
                ]));
                \App\Modules\Clientes\Support\HistoricoDoCliente::ligarVisitasKazaKora($event->user, request());
            }
        });

        Event::listen(function (\Illuminate\Auth\Events\Logout $event): void {
            if ($event->guard === 'web' && $event->user instanceof \App\Models\User) {
                \App\Modules\Clientes\Support\HistoricoDoCliente::kazakora($event->user, 'logout');
            }
        });

        Event::listen(function (\Illuminate\Auth\Events\PasswordReset $event): void {
            if ($event->user instanceof \App\Models\User) {
                \App\Modules\Clientes\Support\HistoricoDoCliente::kazakora($event->user, 'senha_redefinida');
            }
        });

        Event::listen(function (\Illuminate\Auth\Events\Registered $event): void {
            if ($event->user instanceof \App\Models\User) {
                \App\Modules\Clientes\Support\HistoricoDoCliente::cadastroKazaKora($event->user, 'formulário de cadastro');
            }
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
        // Webhook do Mercado Livre (auditoria 2026-11-25): o ML não assina,
        // então o limite por IP segura quem tentar encher a fila.
        RateLimiter::for('ml-webhook', fn (Request $request) => Limit::perMinute(max(1, (int) config('mercadolivre.webhook_por_minuto', 600)))
            ->by('ml-webhook:'.$request->ip()));

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
