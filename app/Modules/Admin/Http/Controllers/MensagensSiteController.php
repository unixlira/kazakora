<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contato\Models\MensagemContato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Caixa de e-mails do site (pedido 2026-10-10): as mensagens do "Fale
 * conosco" da loja, no mesmo jeito das conversas do WhatsApp — contador no
 * menu e aviso no topo quando chega uma nova (ver WhatsAppToasts.vue).
 *
 * Excluir (pedido 2026-10-12) é só no sistema e vai para a lixeira (soft
 * delete), com restaurar. A cópia que chegou no e-mail da loja não é tocada.
 */
class MensagensSiteController extends Controller
{
    public function index(Request $request): Response
    {
        $filtro = in_array($request->query('filtro'), ['nao-lidas', 'lixeira'], true) ? $request->query('filtro') : 'todas';
        $busca = trim((string) $request->query('busca', ''));

        $mensagens = MensagemContato::query()
            ->when($filtro === 'nao-lidas', fn ($query) => $query->naoLidas())
            ->when($filtro === 'lixeira', fn ($query) => $query->onlyTrashed())
            ->when($busca !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('nome', 'like', "%{$busca}%")
                ->orWhere('email', 'like', "%{$busca}%")
                ->orWhere('assunto', 'like', "%{$busca}%")
                ->orWhere('mensagem', 'like', "%{$busca}%")))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (MensagemContato $mensagem) => $mensagem->paraCaixa());

        return Inertia::render('Admin/MensagensSite/Index', [
            'mensagens' => $mensagens,
            'filtro' => $filtro,
            'busca' => $busca,
            'naoLidas' => MensagemContato::query()->naoLidas()->count(),
            'lidas' => MensagemContato::query()->lidas()->count(),
            'naLixeira' => MensagemContato::onlyTrashed()->count(),
        ]);
    }

    public function excluir(MensagemContato $mensagem): RedirectResponse
    {
        $mensagem->delete();

        return back()->with('success', 'Mensagem enviada para a lixeira.');
    }

    public function excluirSelecionadas(Request $request): RedirectResponse
    {
        $total = MensagemContato::query()->whereIn('id', $this->ids($request))->delete();

        return back()->with('success', $this->plural($total, 'mensagem enviada', 'mensagens enviadas').' para a lixeira.');
    }

    public function excluirLidas(): RedirectResponse
    {
        $total = MensagemContato::query()->lidas()->delete();

        return back()->with('success', $this->plural($total, 'mensagem lida enviada', 'mensagens lidas enviadas').' para a lixeira.');
    }

    public function restaurar(Request $request): RedirectResponse
    {
        $total = MensagemContato::onlyTrashed()->whereIn('id', $this->ids($request))->restore();

        return back()->with('success', $this->plural($total, 'mensagem restaurada', 'mensagens restauradas').'.');
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $dados = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        return array_values(array_unique(array_map('intval', $dados['ids'])));
    }

    private function plural(int $total, string $um, string $varios): string
    {
        return $total === 1 ? "1 {$um}" : "{$total} {$varios}";
    }

    public function marcarLida(MensagemContato $mensagem): RedirectResponse
    {
        $mensagem->forceFill(['lida_em' => $mensagem->lida_em ?? now()])->save();

        return back();
    }

    public function marcarNaoLida(MensagemContato $mensagem): RedirectResponse
    {
        $mensagem->forceFill(['lida_em' => null])->save();

        return back();
    }

    /** Aviso em tempo real (mesmo esquema de /admin/whatsapp/conversas/chegando). */
    public function chegando(Request $request): JsonResponse
    {
        $naoLidas = MensagemContato::query()->naoLidas()->count();
        $ultimo = (int) MensagemContato::query()->max('id');

        if (! $request->filled('after')) {
            return response()->json(['lastId' => $ultimo, 'mensagens' => [], 'naoLidas' => $naoLidas]);
        }

        $novas = MensagemContato::query()
            ->where('id', '>', (int) $request->query('after'))
            ->orderBy('id')
            ->limit(10)
            ->get();

        return response()->json([
            'lastId' => max((int) $request->query('after'), $ultimo),
            'naoLidas' => $naoLidas,
            'mensagens' => $novas->map(fn (MensagemContato $mensagem) => $mensagem->paraCaixa()),
        ]);
    }
}
