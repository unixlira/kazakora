<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\PixelMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/** Configurações › Pixel da Meta (pedido do Lira 2026-10-16). */
class PixelMetaController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Admin/PixelMeta/Index', [
            'pixelId' => PixelMeta::id(),
            'origem' => PixelMeta::origem(),
            'pixelEnv' => PixelMeta::doEnv(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pixel_id' => ['nullable', 'string', 'regex:/^\d{10,20}$/'],
        ], [
            'pixel_id.regex' => 'O ID do Pixel tem só números (10 a 20 dígitos), como aparece no Gerenciador de Eventos da Meta.',
        ]);

        $anterior = PixelMeta::id();
        PixelMeta::definir($validated['pixel_id'] ?? null);

        Log::info('Pixel da Meta alterado no admin', [
            'usuario' => $request->user()?->id,
            'anterior' => $anterior,
            'novo' => PixelMeta::id(),
        ]);

        return back()->with('success', PixelMeta::id()
            ? 'Pixel da Meta salvo. O site já usa o novo ID.'
            : 'Pixel da Meta removido. O site parou de carregar o pixel.');
    }
}
