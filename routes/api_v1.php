<?php

use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Support\Rbac\Permissions;
use Illuminate\Support\Facades\Route;

/*
 * API pública de parceiros externos — pedido explícito 2026-08-21.
 * Incluído a partir de routes/api.php sob o prefixo /api/v1, com
 * auth:sanctum + log.api + throttle:api aplicados no grupo inteiro (ver
 * lá). Cada rota autenticada declara sua(s) ability(ies) via
 * `abilities:...` (TODAS exigidas) ou `ability:...` (QUALQUER uma) —
 * mesmo vocabulário de permissão do RBAC interno (App\Support\Rbac\
 * Permissions), reaproveitado tal e qual pros tokens de parceiro (ver
 * App\Models\ApiPartner::allowedAbilities()).
 *
 * As duas rotas de download (etiqueta/DANFE) ficam FORA do grupo
 * autenticado — usam link assinado temporário (`signed`), não o token do
 * parceiro direto (ver docblock de ShipmentController/InvoiceController).
 */

Route::get('/pedidos/{order}/etiqueta', [ShipmentController::class, 'label'])
    ->middleware('signed')
    ->name('api.v1.pedidos.etiqueta');

Route::get('/pedidos/{order}/nota/danfe', [InvoiceController::class, 'danfe'])
    ->middleware('signed')
    ->name('api.v1.pedidos.nota.danfe');

Route::middleware(['auth:sanctum', 'api.partner.active', 'log.api', 'throttle:api'])->group(function () {
    Route::get('/me', [MeController::class, 'show'])->name('api.v1.me');

    Route::middleware('ability:'.Permissions::CADASTROS_VIEW)->group(function () {
        Route::get('/produtos', [ProductController::class, 'index'])->name('api.v1.produtos.index');
        Route::get('/produtos/{product}', [ProductController::class, 'show'])->name('api.v1.produtos.show');
    });

    Route::post('/produtos', [ProductController::class, 'store'])
        ->middleware('abilities:'.Permissions::CADASTROS_CREATE)
        ->name('api.v1.produtos.store');

    Route::match(['put', 'patch'], '/produtos/{product}', [ProductController::class, 'update'])
        ->middleware('abilities:'.Permissions::CADASTROS_EDIT)
        ->name('api.v1.produtos.update');

    Route::delete('/produtos/{product}', [ProductController::class, 'destroy'])
        ->middleware('abilities:'.Permissions::CADASTROS_DELETE)
        ->name('api.v1.produtos.destroy');

    Route::post('/produtos/{product}/estoque/ajustar', [ProductController::class, 'adjustStock'])
        ->middleware('abilities:'.Permissions::ESTOQUE_ADJUST)
        ->name('api.v1.produtos.estoque.ajustar');

    Route::middleware('ability:'.Permissions::PEDIDOS_VIEW)->group(function () {
        Route::get('/pedidos', [OrderController::class, 'index'])->name('api.v1.pedidos.index');
        Route::get('/pedidos/{order}', [OrderController::class, 'show'])->name('api.v1.pedidos.show');
        Route::get('/pedidos/{order}/nota', [InvoiceController::class, 'show'])->name('api.v1.pedidos.nota.show');
        Route::get('/pedidos/{order}/nota/danfe/url', [InvoiceController::class, 'danfeUrl'])->name('api.v1.pedidos.nota.danfe.url');
    });

    Route::patch('/pedidos/{order}/status', [OrderController::class, 'updateStatus'])
        ->middleware('abilities:'.Permissions::PEDIDOS_EDIT)
        ->name('api.v1.pedidos.status');

    Route::post('/pedidos/{order}/nota', [InvoiceController::class, 'store'])
        ->middleware('abilities:'.Permissions::PEDIDOS_EDIT)
        ->name('api.v1.pedidos.nota.store');
});
