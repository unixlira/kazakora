<?php

use App\Http\Controllers\Api\AmazonController;
use App\Http\Controllers\Api\DashboardAgentController;
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

// O painel do Mercado Pago testa se a URL de callback/webhook existe com um
// GET simples antes de liberar a configuração — mesmo caso já resolvido pra
// Shopee logo abaixo (webhook.verify). Sem isso, o teste deles dá erro mesmo
// com a integração certa do nosso lado (a rota POST acima sozinha devolvia
// 405 pro teste GET deles).
Route::match(['get', 'head'], '/mercadopago/callback', fn () => response()->json(['status' => 'ok']))->name('api.mercadopago.callback');
Route::match(['get', 'head'], '/mercadopago/webhook', fn () => response()->json(['status' => 'ok']))->name('api.mercadopago.webhook.verify');

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

Route::prefix('shopee')->name('api.shopee.')->group(function () {
    // OAuth: navegador do admin logado, precisa de sessão/CSRF (ver
    // /api/mercadolivre acima).
    Route::middleware(['web', 'auth', 'admin'])->group(function () {
        Route::get('/auth', [ShopeeController::class, 'redirectToAuth'])->name('auth');
        Route::get('/callback', [ShopeeController::class, 'callback'])->name('callback');
    });

    // Chamado pelos servidores da Shopee (Push Notification), não por um
    // navegador — sem sessão/CSRF, a assinatura é verificada dentro do
    // controller.
    Route::post('/webhook', [ShopeeController::class, 'webhook'])->name('webhook');

    // O painel da Shopee testa se a "Test Callback URL" existe com um GET
    // simples antes de liberar o teste de push de verdade — sem isso a
    // rota (só POST) devolvia 405 e a Shopee mostrava "callback_url is
    // invalid" mesmo com tudo certo do lado de cá.
    Route::match(['get', 'head'], '/webhook', fn () => response()->json(['status' => 'ok']))->name('webhook.verify');
});

Route::prefix('amazon')->name('api.amazon.')->middleware(['web', 'auth', 'admin'])->group(function () {
    // OAuth completo — só é o caminho real se o app SP-API vier a ser
    // publicado; enquanto for privado, a conexão de verdade acontece via
    // token colado manualmente (ver IntegrationController::connectAmazon()).
    Route::get('/auth', [AmazonController::class, 'redirectToAuth'])->name('auth');
    Route::get('/callback', [AmazonController::class, 'callback'])->name('callback');
});

// Chamado pelo agente local de impressão (fora deste servidor) — token fixo,
// não sessão. Ver AuthenticatePrintAgent.
Route::prefix('print-agent')->name('api.print-agent.')->middleware('print.agent')->group(function () {
    Route::get('/jobs', [PrintAgentController::class, 'index'])->name('jobs.index');
    Route::post('/jobs/{printJob}/claim', [PrintAgentController::class, 'claim'])->name('jobs.claim');
    Route::get('/jobs/{printJob}/label', [PrintAgentController::class, 'label'])->name('jobs.label');
    Route::get('/jobs/{printJob}/archive', [PrintAgentController::class, 'archive'])->name('jobs.archive');
    Route::post('/jobs/{printJob}/complete', [PrintAgentController::class, 'complete'])->name('jobs.complete');
    Route::get('/dashboard/channels', [DashboardAgentController::class, 'channels'])->name('dashboard.channels');
    Route::get('/dashboard/metrics', [DashboardAgentController::class, 'metrics'])->name('dashboard.metrics');
    Route::get('/dashboard/channels/{channel}/orders', [DashboardAgentController::class, 'channelOrders'])->name('dashboard.channel-orders');
    Route::get('/dashboard/labels', [DashboardAgentController::class, 'labels'])->name('dashboard.labels');
    Route::get('/dashboard/queue', [DashboardAgentController::class, 'queue'])->name('dashboard.queue');
    Route::post('/dashboard/queue/{order}/pack', [DashboardAgentController::class, 'packOrder'])->name('dashboard.queue.pack');
    Route::get('/dashboard/daily-text', [DashboardAgentController::class, 'dailyText'])->name('dashboard.daily-text');
});
