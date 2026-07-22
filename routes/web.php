<?php

use App\Modules\Admin\Http\Controllers\CategoryController;
use App\Modules\Admin\Http\Controllers\CompanyController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\OrderController as AdminOrderController;
use App\Modules\Admin\Http\Controllers\ProductController;
use App\Modules\Admin\Http\Controllers\ProductFiscalController;
use App\Modules\Admin\Http\Controllers\ProductImageController;
use App\Modules\Admin\Http\Controllers\ProductLogisticsController;
use App\Modules\Admin\Http\Controllers\ProductVideoController;
use App\Modules\Cart\Http\Controllers\CartController;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use App\Modules\Marketplace\Http\Controllers\ProductChannelController;
use App\Modules\Profile\Http\Controllers\ProfileAvatarController;
use App\Modules\Profile\Http\Controllers\ProfileController;
use App\Modules\Profile\Http\Controllers\ProfilePasswordController;
use App\Modules\Profile\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('catalog.index');

Route::prefix('cart')->name('cart.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('index');
    Route::post('/', [CartController::class, 'store'])->name('store');
    Route::patch('/{product}', [CartController::class, 'update'])->name('update');
    Route::delete('/{product}', [CartController::class, 'destroy'])->name('destroy');
});

Route::prefix('checkout')->name('checkout.')->middleware('auth')->group(function () {
    Route::get('/', [CheckoutController::class, 'index'])->name('index');
    Route::post('/', [CheckoutController::class, 'store'])->name('store');
    Route::get('/{order}/confirmacao', [CheckoutController::class, 'confirmation'])->name('confirmation');
});

// Área de conta do usuário autenticado — acessível tanto pela loja quanto
// pelo painel admin, sempre operando sobre o próprio usuário logado.
Route::middleware('auth')->group(function () {
    Route::get('/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/perfil/senha', [ProfilePasswordController::class, 'update'])->name('profile.password.update');
    Route::post('/perfil/avatar', [ProfileAvatarController::class, 'store'])->name('profile.avatar.store');
    Route::delete('/perfil/avatar', [ProfileAvatarController::class, 'destroy'])->name('profile.avatar.destroy');

    Route::get('/configuracoes', [SettingsController::class, 'edit'])->name('settings.edit');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('products', ProductController::class)->except('show');
    Route::resource('categories', CategoryController::class)->except('show');

    Route::put('products/{product}/fiscal', [ProductFiscalController::class, 'update'])->name('products.fiscal.update');
    Route::put('products/{product}/logistics', [ProductLogisticsController::class, 'update'])->name('products.logistics.update');
    Route::post('products/{product}/images', [ProductImageController::class, 'store'])->name('products.images.store');
    Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
    Route::post('products/{product}/video', [ProductVideoController::class, 'store'])->name('products.video.store');
    Route::delete('products/{product}/video', [ProductVideoController::class, 'destroy'])->name('products.video.destroy');
    Route::put('products/{product}/channels/{channel}', [ProductChannelController::class, 'update'])->name('products.channels.update');

    Route::get('orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('orders/{order}', [AdminOrderController::class, 'update'])->name('orders.update');

    Route::get('empresa', [CompanyController::class, 'edit'])->name('company.edit');
    Route::put('empresa', [CompanyController::class, 'update'])->name('company.update');
});

require __DIR__.'/auth.php';
