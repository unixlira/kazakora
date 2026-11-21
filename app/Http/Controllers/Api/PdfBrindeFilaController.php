<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\PdfBrindeFila;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Fila de PDFs de brinde para o Homer (20/11/2026), com o mesmo token da Vids
 * (VIDS_TOKEN). O Homer pede a próxima, produz o PDF e a capa e entrega; o
 * livro entra DESATIVADO, ligado ao produto, e o item fica "Para revisar" até
 * o Lira aprovar no admin.
 */
class PdfBrindeFilaController extends Controller
{
    /** Pega a próxima da fila (ou uma que ficou presa em "produzindo") e marca "produzindo". */
    public function proxima(): JsonResponse
    {
        $item = DB::transaction(function () {
            $item = PdfBrindeFila::query()
                ->where(fn ($q) => $q->where('status', 'fila')
                    ->orWhere(fn ($p) => $p->where('status', 'produzindo')->where('pego_em', '<', now()->subMinutes(PdfBrindeFila::MINUTOS_PRESO))))
                ->orderBy('prioridade')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $item?->update(['status' => 'produzindo', 'pego_em' => now()]);

            return $item;
        });

        if (! $item) {
            return response()->json(['item' => null]);
        }

        $item->load('produto.category', 'produto.images');
        $produto = $item->produto;

        return response()->json(['item' => $item->resumo() + [
            'produto' => [
                'id' => $produto->id,
                'nome' => $produto->name,
                'categoria' => $produto->category?->name,
                'descricao' => mb_substr(strip_tags((string) $produto->description), 0, 3000),
                'url' => route('produtos.ver', $produto->slug),
                'fotos' => $produto->images->take(4)->map(fn ($img) => $img->url)->values(),
            ],
            // Outros PDFs do mesmo produto (as 2 histórias e o colorir usam os mesmos personagens).
            'irmaos' => PdfBrindeFila::query()->where('product_id', $produto->id)->whereKeyNot($item->id)
                ->get(['id', 'tipo', 'titulo', 'status'])->toArray(),
        ]]);
    }

    /** Entrega o PDF: cria o livro (desativado) ligado ao produto e manda o item para revisão. */
    public function entregar(Request $request, PdfBrindeFila $item): JsonResponse
    {
        $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
            'capa' => ['nullable', 'image', 'max:5120'],
            'imagem_quadro' => ['nullable', 'image', 'max:5120'],
            'titulo' => ['nullable', 'string', 'max:255'],
        ]);

        $pdf = $request->file('pdf');
        $campos = [
            'titulo' => $request->input('titulo') ?: $item->titulo,
            'arquivo' => $pdf->store('livros-digitais', 'local'),
            'nome_arquivo' => $pdf->getClientOriginalName(),
            'tamanho' => $pdf->getSize(),
            'ativo' => false,
            'todos_produtos' => false,
        ];
        if ($request->hasFile('capa')) {
            $campos['capa'] = $request->file('capa')->store('livros-digitais', 'public');
        }
        if ($request->hasFile('imagem_quadro')) {
            $campos['imagem_quadro'] = $request->file('imagem_quadro')->store('livros-digitais/quadros', 'public');
        }

        // Devolvido para refazer: troca o PDF do mesmo livro em vez de criar outro.
        $livro = $item->livro;
        if ($livro) {
            Storage::disk('local')->delete($livro->arquivo);
            if (isset($campos['capa']) && $livro->capa) {
                Storage::disk('public')->delete($livro->capa);
            }
            if (isset($campos['imagem_quadro']) && $livro->imagem_quadro) {
                Storage::disk('public')->delete($livro->imagem_quadro);
            }
            $livro->update($campos);
        } else {
            $livro = LivroDigital::query()->create($campos);
        }
        $livro->produtos()->syncWithoutDetaching([$item->product_id]);
        PromocoesDoProduto::limparCache();

        $item->update(['status' => 'revisao', 'livro_digital_id' => $livro->id]);

        return response()->json(['item' => $item->fresh('livro')->resumo()]);
    }

    public function erro(Request $request, PdfBrindeFila $item): JsonResponse
    {
        $motivo = $request->validate(['erro' => ['required', 'string', 'max:2000']])['erro'];
        $item->update(['status' => 'erro', 'observacao' => $motivo]);

        return response()->json(['item' => $item->resumo()]);
    }
}
