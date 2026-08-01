<?php

use App\Http\Controllers\Api\MelhorEnvioController;
use App\Http\Controllers\Api\MercadoLivreController;
use App\Http\Controllers\Api\MercadoPagoWebhookController;
use App\Http\Controllers\Api\PrintAgentController;
use App\Http\Controllers\Api\ShopeeController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Chamado pelos servidores do Stripe, não por um navegador — sem sessão/CSRF
// (ver bootstrap/app.php), a assinatura é verificada dentro do controller.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->name('api.stripe.webhook');

// Idem, pro Mercado Pago — assinatura HMAC verificada dentro do controller.
Route::post('/mercadopago/webhook', [MercadoPagoWebhookController::class, 'handle'])->name('api.mercadopago.webhook');

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

Route::prefix('melhorenvio')->name('api.melhorenvio.')->middleware(['web', 'auth', 'admin'])->group(function () {
    Route::get('/auth', [MelhorEnvioController::class, 'redirectToAuth'])->name('auth');
    Route::get('/callback', [MelhorEnvioController::class, 'callback'])->name('callback');
});

Route::prefix('shopee')->name('api.shopee.')->middleware(['web', 'auth', 'admin'])->group(function () {
    Route::get('/auth', [ShopeeController::class, 'redirectToAuth'])->name('auth');
    Route::get('/callback', [ShopeeController::class, 'callback'])->name('callback');
});

// Chamado pelo agente local de impressão (fora deste servidor) — token fixo,
// não sessão. Ver AuthenticatePrintAgent.
Route::prefix('print-agent')->name('api.print-agent.')->middleware('print.agent')->group(function () {
    Route::get('/jobs', [PrintAgentController::class, 'index'])->name('jobs.index');
    Route::post('/jobs/{printJob}/claim', [PrintAgentController::class, 'claim'])->name('jobs.claim');
    Route::get('/jobs/{printJob}/label', [PrintAgentController::class, 'label'])->name('jobs.label');
    Route::post('/jobs/{printJob}/complete', [PrintAgentController::class, 'complete'])->name('jobs.complete');
});
