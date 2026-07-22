<?php

use App\Modules\Admin\Http\Controllers\AuditLogController;
use App\Modules\Admin\Http\Controllers\CategoryController;
use App\Modules\Admin\Http\Controllers\CompanyController;
use App\Modules\Admin\Http\Controllers\CostCenterController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\OrderController as AdminOrderController;
use App\Modules\Admin\Http\Controllers\ProductController;
use App\Modules\Admin\Http\Controllers\ProductFiscalController;
use App\Modules\Admin\Http\Controllers\ProductImageController;
use App\Modules\Admin\Http\Controllers\ProductLogisticsController;
use App\Modules\Admin\Http\Controllers\ProductVideoController;
use App\Modules\Admin\Http\Controllers\SupplierController;
use App\Modules\Admin\Http\Controllers\UserPermissionController;
use App\Modules\Cart\Http\Controllers\CartController;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use App\Modules\Marketplace\Http\Controllers\ProductChannelController;
use App\Modules\Profile\Http\Controllers\ProfileAvatarController;
use App\Modules\Profile\Http\Controllers\ProfileController;
use App\Modules\Profile\Http\Controllers\ProfilePasswordController;
use App\Modules\Profile\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('catalogo.inicio');

Route::prefix('carrinho')->name('carrinho.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('ver');
    Route::post('/', [CartController::class, 'store'])->name('adicionar');
    Route::patch('/{product}', [CartController::class, 'update'])->name('atualizar');
    Route::delete('/{product}', [CartController::class, 'destroy'])->name('remover');
});

Route::prefix('finalizacao')->name('finalizacao.')->middleware('auth')->group(function () {
    Route::get('/', [CheckoutController::class, 'index'])->name('ver');
    Route::post('/', [CheckoutController::class, 'store'])->name('finalizar');
    Route::get('/{order}/confirmacao', [CheckoutController::class, 'confirmation'])->name('confirmacao');
});

// Área de conta do usuário autenticado — acessível tanto pela loja quanto
// pelo painel admin, sempre operando sobre o próprio usuário logado.
Route::middleware('auth')->group(function () {
    Route::get('/perfil', [ProfileController::class, 'edit'])->name('perfil.editar');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('perfil.atualizar');
    Route::put('/perfil/senha', [ProfilePasswordController::class, 'update'])->name('perfil.senha.atualizar');
    Route::post('/perfil/avatar', [ProfileAvatarController::class, 'store'])->name('perfil.avatar.adicionar');
    Route::delete('/perfil/avatar', [ProfileAvatarController::class, 'destroy'])->name('perfil.avatar.remover');

    Route::get('/configuracoes', [SettingsController::class, 'edit'])->name('configuracoes.editar');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'staff'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('painel');

    Route::resource('produtos', ProductController::class)->except('show')
        ->parameters(['produtos' => 'product'])
        ->names([
            'index' => 'produtos.listar',
            'create' => 'produtos.criar',
            'store' => 'produtos.armazenar',
            'edit' => 'produtos.editar',
            'update' => 'produtos.atualizar',
            'destroy' => 'produtos.excluir',
        ])
        ->middlewareFor(['create', 'store'], 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::resource('categorias', CategoryController::class)->except('show')
        ->parameters(['categorias' => 'category'])
        ->names([
            'index' => 'categorias.listar',
            'create' => 'categorias.criar',
            'store' => 'categorias.armazenar',
            'edit' => 'categorias.editar',
            'update' => 'categorias.atualizar',
            'destroy' => 'categorias.excluir',
        ])
        ->middlewareFor(['create', 'store'], 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::resource('fornecedores', SupplierController::class)->except('show')
        ->parameters(['fornecedores' => 'supplier'])
        ->names([
            'index' => 'fornecedores.listar',
            'create' => 'fornecedores.criar',
            'store' => 'fornecedores.armazenar',
            'edit' => 'fornecedores.editar',
            'update' => 'fornecedores.atualizar',
            'destroy' => 'fornecedores.excluir',
        ])
        ->middlewareFor(['create', 'store'], 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::resource('centros-de-custo', CostCenterController::class)->except('show')
        ->parameters(['centros-de-custo' => 'cost_center'])
        ->names([
            'index' => 'centros-de-custo.listar',
            'create' => 'centros-de-custo.criar',
            'store' => 'centros-de-custo.armazenar',
            'edit' => 'centros-de-custo.editar',
            'update' => 'centros-de-custo.atualizar',
            'destroy' => 'centros-de-custo.excluir',
        ])
        ->middlewareFor(['create', 'store'], 'permission:cadastros.create')
        ->middlewareFor(['edit', 'update'], 'permission:cadastros.edit')
        ->middlewareFor('destroy', 'permission:cadastros.delete');

    Route::put('produtos/{product}/fiscal', [ProductFiscalController::class, 'update'])->name('produtos.fiscal.atualizar');
    Route::put('produtos/{product}/logistica', [ProductLogisticsController::class, 'update'])->name('produtos.logistica.atualizar');
    Route::post('produtos/{product}/imagens', [ProductImageController::class, 'store'])->name('produtos.imagens.adicionar');
    Route::delete('produtos/{product}/imagens/{image}', [ProductImageController::class, 'destroy'])->name('produtos.imagens.remover');
    Route::post('produtos/{product}/video', [ProductVideoController::class, 'store'])->name('produtos.video.adicionar');
    Route::delete('produtos/{product}/video', [ProductVideoController::class, 'destroy'])->name('produtos.video.remover');
    Route::put('produtos/{product}/canais/{channel}', [ProductChannelController::class, 'update'])->name('produtos.canais.atualizar');

    Route::get('pedidos', [AdminOrderController::class, 'index'])->name('pedidos.listar');
    Route::get('pedidos/{order}', [AdminOrderController::class, 'show'])->name('pedidos.exibir');
    Route::patch('pedidos/{order}', [AdminOrderController::class, 'update'])
        ->name('pedidos.atualizar')
        ->middleware('permission:pedidos.edit');

    Route::get('empresa', [CompanyController::class, 'edit'])->name('empresa.editar');
    Route::put('empresa', [CompanyController::class, 'update'])->name('empresa.atualizar');

    Route::middleware('admin')->group(function () {
        Route::get('usuarios-permissoes', [UserPermissionController::class, 'index'])->name('usuarios-permissoes.listar');
        Route::patch('usuarios-permissoes/usuarios/{user}', [UserPermissionController::class, 'updateRole'])->name('usuarios-permissoes.papel.atualizar');
        Route::put('usuarios-permissoes/matriz', [UserPermissionController::class, 'updatePermissions'])->name('usuarios-permissoes.matriz.atualizar');

        Route::get('auditoria', [AuditLogController::class, 'index'])->name('auditoria.listar');
    });
});

require __DIR__.'/auth.php';
