<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Promocao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vender › Promoções (pedido 2026-10-21): Promoção Airfryer e Promoção PDFs.
 * Cada uma tem ativa/inativa, período, valor mínimo do pedido, observações e
 * chaves/regras livres que o Lira define.
 */
class PromocaoController extends Controller
{
    public function show(string $slug): Response
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();

        return Inertia::render('Admin/Promocoes/Editar', [
            'promocao' => [
                'slug' => $promocao->slug,
                'nome' => $promocao->nome,
                'ativa' => $promocao->ativa,
                'inicio' => $promocao->inicio?->format('Y-m-d\TH:i'),
                'fim' => $promocao->fim?->format('Y-m-d\TH:i'),
                'valor_minimo' => $promocao->valor_minimo,
                'observacoes' => $promocao->observacoes,
                'regras' => collect($promocao->regras ?? [])
                    ->map(fn ($valor, $chave) => ['chave' => (string) $chave, 'valor' => (string) $valor])
                    ->values(),
            ],
        ]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();

        $dados = $request->validate([
            'ativa' => ['boolean'],
            'inicio' => ['nullable', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'valor_minimo' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'regras' => ['nullable', 'array', 'max:50'],
            'regras.*.chave' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'regras.*.valor' => ['nullable', 'string', 'max:500'],
        ], [
            'fim.after_or_equal' => 'O fim precisa ser depois do início.',
            'regras.*.chave.required' => 'Toda regra precisa de uma chave.',
            'regras.*.chave.regex' => 'A chave usa só letras, números, ponto, hífen e _.',
        ]);

        $regras = [];
        foreach ($dados['regras'] ?? [] as $regra) {
            $regras[$regra['chave']] = $regra['valor'] ?? '';
        }

        $promocao->update([
            'ativa' => $request->boolean('ativa'),
            'inicio' => $dados['inicio'] ?? null,
            'fim' => $dados['fim'] ?? null,
            'valor_minimo' => $dados['valor_minimo'] ?? null,
            'observacoes' => ($dados['observacoes'] ?? '') !== '' ? $dados['observacoes'] : null,
            'regras' => $regras ?: null,
        ]);

        return back()->with('success', "{$promocao->nome} salva.");
    }
}
