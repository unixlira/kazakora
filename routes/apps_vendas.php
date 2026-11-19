<?php

use App\Modules\Notifications\Push\AppsDeVendas;
use App\Modules\Notifications\Push\Http\AppVendasController;
use App\Modules\Notifications\Push\Http\Middleware\AutenticarAppVendas;
use App\Modules\Notifications\Push\Http\Middleware\CorsAppsVendas;
use Illuminate\Support\Facades\Route;

/*
 * API dos apps de vendas (2026-11-19): https://app.kazakora.com e
 * https://app.ferrakora.com são sites próprios que leem daqui por token
 * (TokensDoApp). Prefixo /api-apps-vendas, fora de /api/* (CORS próprio,
 * CorsAppsVendas). Registrado em bootstrap/app.php com o grupo 'api'.
 */
Route::prefix('{app}')->whereIn('app', AppsDeVendas::TODOS)->name('apps-vendas.')
    ->middleware(CorsAppsVendas::class)
    ->controller(AppVendasController::class)->group(function () {
        Route::options('{qualquer?}', fn () => response('', 204))->where('qualquer', '.*');
        Route::post('login', 'login')->middleware('throttle:10,1')->name('login');

        Route::middleware([AutenticarAppVendas::class, 'throttle:120,1'])->group(function () {
            Route::get('sessao', 'sessao')->name('sessao');
            Route::post('sair', 'sair')->name('sair');
            Route::get('metricas', 'metricas')->name('metricas');
            Route::get('pedidos', 'pedidos')->name('pedidos');
            Route::get('pedidos/{pedido}', 'pedido')->whereNumber('pedido')->name('pedido');
            Route::get('produtos', 'produtos')->name('produtos');
            Route::get('campanhas', 'campanhas')->name('campanhas');
            Route::get('estado', 'estado')->name('estado');
            Route::post('inscrever', 'inscrever')->middleware('throttle:20,1')->name('inscrever');
            Route::post('cancelar', 'cancelar')->middleware('throttle:20,1')->name('cancelar');
            Route::post('teste', 'teste')->middleware('throttle:6,1')->name('teste');
        });
    });
