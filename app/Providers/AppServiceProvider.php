<?php

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
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
    }
}
