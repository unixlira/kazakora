<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de avaliações no admin (pedido explícito 2026-08-16) — lista/vê/edita
 * tanto avaliações reais do site quanto as importadas dos marketplaces
 * (ver ReviewImportService). Diferente do storefront público, o admin PODE
 * ver de qual canal cada uma veio (->makeVisible) — é justamente pra dar
 * esse controle interno que a tela existe; a regra de "não identificar a
 * plataforma" é só pro público, ver Review::$hidden.
 */
class ReviewController extends Controller
{
    public function index(): Response
    {
        $reviews = Review::query()
            ->with(['product:id,name,slug', 'user:id,name'])
            ->withCount('images')
            ->latest()
            ->get()
            ->makeVisible(['channel', 'external_id']);

        return Inertia::render('Admin/Reviews/Index', [
            'reviews' => $reviews,
        ]);
    }

    public function show(Review $review): Response
    {
        $review->load(['product:id,name,slug', 'user:id,name', 'images']);
        $review->makeVisible(['channel', 'external_id']);

        return Inertia::render('Admin/Reviews/Show', [
            'review' => $review,
        ]);
    }

    public function edit(Review $review): Response
    {
        $review->load(['product:id,name,slug', 'user:id,name', 'images']);
        $review->makeVisible(['channel', 'external_id']);

        return Inertia::render('Admin/Reviews/Edit', [
            'review' => $review,
        ]);
    }

    public function update(Request $request, Review $review): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'reviewer_name' => ['nullable', 'string', 'max:255'],
        ]);

        // reviewer_name só se aplica quando não há usuário real vinculado
        // (avaliação importada) — editar o nome de uma avaliação do site
        // não faria sentido, ela sempre mostra review.user.name.
        if ($review->user_id) {
            unset($validated['reviewer_name']);
        }

        $review->update($validated);

        return redirect()->route('admin.avaliacoes.listar')->with('success', 'Avaliação atualizada.');
    }

    public function destroy(Review $review): RedirectResponse
    {
        $review->delete();

        return back()->with('success', 'Avaliação removida.');
    }
}
