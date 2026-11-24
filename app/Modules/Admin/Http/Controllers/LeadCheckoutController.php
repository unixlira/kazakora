<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Support\RecuperacaoCarrinhoWhatsApp;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Support\SiteAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Carrinhos abandonados (pedido 2026-10-24). Lista quem chegou na
 * Entrega do checkout, com os itens e se virou pedido. Desde 2026-11-23 (pedido
 * do Lira) tem o botão de WhatsApp por carrinho e o "Disparar para todos", com
 * o modelo aprovado de recuperação de carrinho; cada carrinho recebe uma vez.
 */
class LeadCheckoutController extends Controller
{
    private const PER_PAGE = 50;

    /** Teto por clique em "Disparar para todos" (o envio é na hora, um por um). */
    private const LIMITE_DISPARO = 100;

    public function index(Request $request): Response
    {
        $status = in_array($request->string('status')->toString(), [LeadCheckout::STATUS_ABERTO, LeadCheckout::STATUS_CONVERTIDO], true)
            ? $request->string('status')->toString()
            : null;
        $site = isset(SiteAtual::SITES[$request->string('site')->toString()]) ? $request->string('site')->toString() : null;
        $busca = trim($request->string('search')->toString());

        $leads = LeadCheckout::query()
            ->with('order:id,external_order_id,status')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($site, fn ($q) => $q->where('site', $site))
            ->when($busca !== '', function ($q) use ($busca) {
                $digitos = preg_replace('/\D/', '', $busca);
                $q->where(fn ($q) => $q
                    ->where('nome', 'like', "%{$busca}%")
                    ->orWhere('email', 'like', '%'.mb_strtolower($busca).'%')
                    ->when(strlen($digitos) >= 4, fn ($q) => $q->orWhere('cpf', 'like', "%{$digitos}%")->orWhere('telefone', 'like', "%{$digitos}%")));
            })
            ->latest('updated_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (LeadCheckout $lead) => [
                'id' => $lead->id,
                'site' => $lead->site,
                'nome' => $lead->nome,
                'email' => $lead->email,
                'cpf' => $lead->cpf,
                'telefone' => $lead->telefone,
                'itens' => $lead->itens ?? [],
                'quantidade_itens' => $lead->quantidade_itens,
                'subtotal' => (float) $lead->subtotal,
                'status' => $lead->status,
                'etapa' => $lead->etapa,
                'origem' => $lead->origem,
                'utm_source' => $lead->utm_source,
                'whatsapp_enviado_em' => $lead->whatsapp_enviado_em?->toIso8601String(),
                'whatsapp_bloqueio' => app(RecuperacaoCarrinhoWhatsApp::class)->bloqueio($lead),
                'pedido' => $lead->order ? ['id' => $lead->order->id, 'numero' => $lead->order->external_order_id] : null,
                'criado_em' => $lead->created_at?->toIso8601String(),
                'atualizado_em' => $lead->updated_at?->toIso8601String(),
            ]);

        $base = LeadCheckout::query()->when($site, fn ($q) => $q->where('site', $site));

        return Inertia::render('Admin/LeadsCheckout/Index', [
            'leads' => $leads,
            'resumo' => [
                'abertos' => (clone $base)->where('status', LeadCheckout::STATUS_ABERTO)->count(),
                'convertidos' => (clone $base)->where('status', LeadCheckout::STATUS_CONVERTIDO)->count(),
                'para_disparar' => $this->paraDisparar($site)->count(),
                'valor_aberto' => round((float) (clone $base)->where('status', LeadCheckout::STATUS_ABERTO)->sum('subtotal'), 2),
            ],
            'sites' => collect(SiteAtual::SITES)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values(),
            'filters' => ['status' => $status, 'site' => $site, 'search' => $busca],
        ]);
    }

    /** Um carrinho: manda a mensagem de recuperação pelo WhatsApp. */
    public function whatsapp(LeadCheckout $lead, RecuperacaoCarrinhoWhatsApp $recuperacao): RedirectResponse
    {
        $resultado = $recuperacao->enviar($lead);

        return $resultado === RecuperacaoCarrinhoWhatsApp::OK
            ? back()->with('success', 'Mensagem enviada para '.($lead->nome ?: 'o cliente').'.')
            : back()->with('error', $resultado);
    }

    /** Todos os carrinhos abertos com celular que ainda não receberam (da loja filtrada, se houver). */
    public function whatsappTodos(Request $request, RecuperacaoCarrinhoWhatsApp $recuperacao): RedirectResponse
    {
        $site = isset(SiteAtual::SITES[$request->string('site')->toString()]) ? $request->string('site')->toString() : null;
        $enviados = 0;
        $falhas = 0;
        foreach ($this->paraDisparar($site)->limit(self::LIMITE_DISPARO)->get() as $lead) {
            $recuperacao->enviar($lead) === RecuperacaoCarrinhoWhatsApp::OK ? $enviados++ : $falhas++;
        }

        return back()->with($enviados ? 'success' : 'warning', "Mensagens enviadas: {$enviados}.".($falhas ? " Não enviadas: {$falhas} (celular inválido ou recusado pelo WhatsApp)." : ''));
    }

    private function paraDisparar(?string $site)
    {
        return LeadCheckout::query()
            ->where('status', LeadCheckout::STATUS_ABERTO)
            ->whereNull('whatsapp_enviado_em')
            ->whereNotNull('telefone')->where('telefone', '!=', '')
            ->whereIn('site', $site ? [$site] : ['kazakora', 'ferrakora'])
            ->latest('updated_at');
    }
}
