<?php

use App\Modules\Auth\Http\Controllers\AuthenticatedSessionController;
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

    Route::get('esqueci-senha', [PasswordResetLinkController::class, 'create'])
        ->name('senha.solicitar');

    Route::post('esqueci-senha', [PasswordResetLinkController::class, 'store'])
        ->name('senha.email');

    Route::get('redefinir-senha/{token}', [NewPasswordController::class, 'create'])
        ->name('senha.redefinir');

    Route::post('redefinir-senha', [NewPasswordController::class, 'store'])
        ->name('senha.armazenar');
});

Route::middleware('auth')->group(function () {
    Route::post('sair', [AuthenticatedSessionController::class, 'destroy'])
        ->name('sair');
});
