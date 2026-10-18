<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\ContarAvaliacoesCanaisJob;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Marketplace\Support\ContagemAvaliacoesCanais;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avaliações › Cadastro e controle (pedido do Lira 2026-10-18):
 * 1. cadastrar à mão uma avaliação real (texto, nome, nota, fotos e vídeos),
 *    gravada com channel 'manual' para o admin saber de onde veio;
 * 2. por produto ativo, avaliações reais nos anúncios da Shopee e do ML
 *    contra as que aparecem no site (ContagemAvaliacoesCanais).
 */
class ReviewControleController extends Controller
{
    private const EXTENSOES_VIDEO = ['mp4', 'mov', 'webm'];

    public function index(ContagemAvaliacoesCanais $contagem): Response
    {
        return Inertia::render('Admin/Reviews/Controle', [
            'produtos' => $contagem->porProduto(),
            'ultimaLeitura' => $contagem->ultimaLeitura(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            'reviewer_name' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'data' => ['nullable', 'date', 'before_or_equal:today'],
            'midias' => ['nullable', 'array', 'max:6'],
            'midias.*' => ['file', 'mimes:jpg,jpeg,png,webp,'.implode(',', self::EXTENSOES_VIDEO), 'max:51200'],
        ], [
            'midias.*.mimes' => 'Envie foto (JPG, PNG, WEBP) ou vídeo (MP4, MOV, WEBM).',
            'midias.*.max' => 'Cada arquivo pode ter até 50 MB.',
            'midias.max' => 'No máximo 6 arquivos por avaliação.',
        ]);

        $caminhos = [];
        foreach ($request->file('midias', []) as $arquivo) {
            $caminhos[] = [
                'path' => $arquivo->store('avaliacoes', 'public'),
                'tipo' => in_array(strtolower($arquivo->getClientOriginalExtension()), self::EXTENSOES_VIDEO, true) ? 'video' : 'image',
            ];
        }

        DB::transaction(function () use ($validated, $caminhos) {
            $review = new Review([
                'product_id' => $validated['product_id'],
                'reviewer_name' => trim($validated['reviewer_name']),
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'channel' => Review::CHANNEL_MANUAL,
            ]);
            if (! empty($validated['data'])) {
                $review->created_at = Carbon::parse($validated['data'])->setTimeFrom(now());
            }
            $review->save();

            foreach ($caminhos as $posicao => $midia) {
                $review->images()->create([
                    'image_url' => Storage::disk('public')->url($midia['path']),
                    'media_type' => $midia['tipo'],
                    'position' => $posicao,
                ]);
            }
        });

        $produto = Product::query()->find($validated['product_id'], ['name']);

        return back()->with('success', "Avaliação cadastrada em {$produto?->name}.");
    }

    public function atualizarContagem(): RedirectResponse
    {
        ContarAvaliacoesCanaisJob::dispatch();

        return back()->with('success', 'Contagem da Shopee e do Mercado Livre pedida. Leva cerca de 1 minuto; recarregue a página depois.');
    }
}
