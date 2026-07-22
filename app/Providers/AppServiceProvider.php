<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
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
    }
}
