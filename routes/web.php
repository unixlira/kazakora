<?php

use App\Modules\Admin\Http\Controllers\CategoryController;
use App\Modules\Admin\Http\Controllers\CompanyController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\OrderController as AdminOrderController;
use App\Modules\Admin\Http\Controllers\ProductController;
use App\Modules\Admin\Http\Controllers\ProductFiscalController;
use App\Modules\Admin\Http\Controllers\ProductImageController;
use App\Modules\Admin\Http\Controllers\ProductVideoController;
use App\Modules\Cart\Http\Controllers\CartController;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Checkout\Http\Controllers\CheckoutController;
use App\Modules\Marketplace\Http\Controllers\ProductChannelController;
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

Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('products', ProductController::class)->except('show');
    Route::resource('categories', CategoryController::class)->except('show');

    Route::put('products/{product}/fiscal', [ProductFiscalController::class, 'update'])->name('products.fiscal.update');
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
