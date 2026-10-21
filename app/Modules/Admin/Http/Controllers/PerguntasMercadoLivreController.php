<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MercadoLivrePergunta;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Atendimento > Perguntas Mercado Livre (pedido 2026-10-20): as perguntas que
 * chegam pelo webhook (QuestionService) com o rascunho da Manuela. Nada vai ao
 * comprador sozinho: o Lira revisa, edita se quiser e clica em enviar.
 */
class PerguntasMercadoLivreController extends Controller
{
    public function index(Request $request): Response
    {
        $filtro = in_array($request->query('filtro'), ['respondidas', 'todas'], true) ? $request->query('filtro') : 'pendentes';
        $busca = trim((string) $request->query('busca', ''));

        $perguntas = MercadoLivrePergunta::query()
            ->when($filtro === 'pendentes', fn ($query) => $query->pendentes())
            ->when($filtro === 'respondidas', fn ($query) => $query->whereNotNull('respondida_em'))
            ->when($busca !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('pergunta', 'like', "%{$busca}%")
                ->orWhere('item_id', 'like', "%{$busca}%")))
            ->orderByDesc('perguntada_em')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $produtos = ProductChannelListing::query()
            ->with('product:id,name')
            ->where('channel', 'mercado_livre')
            ->whereIn('external_id', $perguntas->getCollection()->pluck('item_id')->unique())
            ->get()
            ->mapWithKeys(fn (ProductChannelListing $l) => [(string) $l->external_id => $l->product?->name]);

        return Inertia::render('Admin/PerguntasMercadoLivre/Index', [
            'perguntas' => $perguntas->through(fn (MercadoLivrePergunta $p) => $p->paraCaixa($produtos[$p->item_id] ?? null)),
            'filtro' => $filtro,
            'busca' => $busca,
            'pendentes' => MercadoLivrePergunta::query()->pendentes()->count(),
        ]);
    }

    /** Resposta aprovada pelo Lira: vai pública no anúncio, não dá para editar depois. */
    public function enviar(Request $request, MercadoLivrePergunta $pergunta, MercadoLivreClient $ml): RedirectResponse
    {
        $dados = $request->validate(['texto' => ['required', 'string', 'max:2000']]);
        $texto = trim($dados['texto']);

        if ($pergunta->respondida_em !== null) {
            return back()->with('warning', 'Essa pergunta já foi respondida.');
        }

        try {
            // Sem nova tentativa automática: repetir depois de um 5xx pode
            // publicar a resposta duas vezes.
            $ml->postOnce('answers', ['question_id' => $pergunta->question_id, 'text' => $texto]);
        } catch (Throwable $e) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.questions.resposta_falhou', [
                'pergunta' => $pergunta->question_id,
                'erro' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return back()->with('error', 'O Mercado Livre recusou a resposta: '.mb_substr($e->getMessage(), 0, 200));
        }

        $pergunta->update([
            'resposta_enviada' => $texto,
            'respondida_em' => now(),
            'status' => 'ANSWERED',
        ]);

        return back()->with('success', 'Resposta enviada ao Mercado Livre.');
    }
}
