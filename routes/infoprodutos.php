<?php

use App\Modules\Infoprodutos\Http\Controllers\ColetaController;
use App\Modules\Infoprodutos\Http\Controllers\KiwifyWebhookController;
use App\Modules\Infoprodutos\Http\Middleware\CorsInfoprodutos;
use Illuminate\Support\Facades\Route;

/*
 * Medição do funil dos infoprodutos e mapa de cliques (2026-11-19).
 * Prefixo /api-infoprodutos, fora de /api/* (CORS próprio, só os domínios
 * de config infoprodutos.origens). Registrado em bootstrap/app.php com o
 * grupo 'api'.
 */
Route::middleware(CorsInfoprodutos::class)->group(function () {
    Route::options('evento', fn () => response('', 204));
    // 240/min por IP (2026-11-26): agora toda página da loja manda a abertura
    // na hora e a operadora de celular põe muita gente atrás do mesmo IP (CGNAT).
    Route::post('evento', [ColetaController::class, 'registrar'])
        ->middleware('throttle:240,1,infoprodutos-evento')
        ->name('infoprodutos.evento');
});

// A Kiwify chama do servidor dela: sem CORS; autenticação pela assinatura.
Route::post('kiwify/webhook', [KiwifyWebhookController::class, 'receber'])
    ->middleware('throttle:120,1,infoprodutos-kiwify')
    ->name('infoprodutos.kiwify');
