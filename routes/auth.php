<?php

use App\Modules\Auth\Http\Controllers\AuthenticatedSessionController;
use App\Modules\Auth\Http\Controllers\CodigoLoginController;
use App\Modules\Auth\Http\Controllers\NewPasswordController;
use App\Modules\Auth\Http\Controllers\PasswordResetLinkController;
use App\Modules\Auth\Http\Controllers\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('cadastro', [RegisteredUserController::class, 'create'])
        ->name('cadastro');

    Route::post('cadastro', [RegisteredUserController::class, 'store']);

    Route::get('entrar', [AuthenticatedSessionController::class, 'create'])
        ->name('entrar');

    Route::post('entrar', [AuthenticatedSessionController::class, 'store']);

    // Login em 2 fatores do admin (2026-11-25): código por e-mail depois da senha.
    Route::get('entrar/codigo', [CodigoLoginController::class, 'create'])
        ->name('entrar.codigo');
    Route::post('entrar/codigo', [CodigoLoginController::class, 'store'])
        ->middleware('throttle:10,1,admin-2fa-codigo')
        ->name('entrar.codigo.conferir');
    Route::post('entrar/codigo/reenviar', [CodigoLoginController::class, 'reenviar'])
        ->middleware('throttle:3,10,admin-2fa-reenviar')
        ->name('entrar.codigo.reenviar');

    Route::get('esqueci-senha', [PasswordResetLinkController::class, 'create'])
        ->name('senha.solicitar');

    Route::post('esqueci-senha', [PasswordResetLinkController::class, 'store'])
        ->name('senha.email');

    Route::get('redefinir-senha/{token}', [NewPasswordController::class, 'create'])
        ->name('senha.redefinir');

    Route::post('redefinir-senha', [NewPasswordController::class, 'store'])
        ->name('senha.armazenar');
});

// Admin que entra por kazakora.com chega ao painel.kazakora.com já logado.
Route::get('entrar/painel/{user}', [AuthenticatedSessionController::class, 'entrarNoPainel'])
    ->middleware(['signed:relative', 'throttle:10,1'])
    ->name('entrar.painel');

Route::middleware('auth')->group(function () {
    Route::post('sair', [AuthenticatedSessionController::class, 'destroy'])
        ->name('sair');
});
