<?php

use App\Modules\Editorial\Http\Controllers\EditorialController as Editorial;
use Illuminate\Support\Facades\Route;

Route::get('/blog', [Editorial::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [Editorial::class, 'show'])->name('blog.show');
Route::middleware('auth')->group(function () {
    Route::match(['put', 'delete'], '/blog/{slug}/like', [Editorial::class, 'like'])->middleware('throttle:30,1')->name('blog.like');
    Route::post('/blog/{slug}/comments', [Editorial::class, 'comment'])->middleware('throttle:5,1')->name('blog.comments');
});
Route::prefix('admin/editorial')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/posts', [Editorial::class, 'adminIndex']);
    Route::get('/posts/create', [Editorial::class, 'create']);
    Route::post('/posts', [Editorial::class, 'store']);
    Route::get('/posts/{post}/edit', [Editorial::class, 'edit']);
    Route::delete('/posts/{post}', [Editorial::class, 'destroy']);
    Route::put('/posts/{post}', [Editorial::class, 'update']);
    Route::post('/posts/{post}/publish', [Editorial::class, 'publish']);
    Route::post('/posts/{post}/unpublish', [Editorial::class, 'unpublish']);
    Route::get('/posts/{post}/preview', [Editorial::class, 'preview']);
    Route::get('/comments', [Editorial::class, 'comments']);
    Route::post('/comments/{comment}/approve', [Editorial::class, 'moderate'])->name('editorial.comments.approve');
    Route::post('/comments/{comment}/reject', [Editorial::class, 'moderate'])->name('editorial.comments.reject');
});
