<?php

use App\Http\Controllers\Api\MercadoLivreController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Chamado pelos servidores do Stripe, não por um navegador — sem sessão/CSRF
// (ver bootstrap/app.php), a assinatura é verificada dentro do controller.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->name('api.stripe.webhook');

Route::prefix('mercadolivre')->name('api.mercadolivre.')->group(function () {
    // OAuth is initiated/completed by a logged-in admin's browser, so it
    // rides the 'web' stack for session + auth, even though it lives under
    // /api to match the redirect URI already registered in the ML portal.
    Route::middleware(['web', 'auth', 'admin'])->group(function () {
        Route::get('/auth', [MercadoLivreController::class, 'redirectToAuth'])->name('auth');
        Route::get('/callback', [MercadoLivreController::class, 'callback'])->name('callback');
    });

    // Called by Mercado Livre's servers, not a browser — stays stateless
    // and CSRF-exempt (see bootstrap/app.php).
    Route::post('/webhook', [MercadoLivreController::class, 'webhook'])->name('webhook');
});
