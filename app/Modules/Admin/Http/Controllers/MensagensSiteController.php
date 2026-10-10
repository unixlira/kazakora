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
 */
class MensagensSiteController extends Controller
{
    public function index(Request $request): Response
    {
        $filtro = $request->query('filtro') === 'nao-lidas' ? 'nao-lidas' : 'todas';
        $busca = trim((string) $request->query('busca', ''));

        $mensagens = MensagemContato::query()
            ->when($filtro === 'nao-lidas', fn ($query) => $query->naoLidas())
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
        ]);
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
