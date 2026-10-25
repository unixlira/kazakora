<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Support\SiteAtual;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Carrinhos abandonados (pedido 2026-10-24): só leitura. Lista quem
 * chegou na Entrega do checkout, com os itens e se virou pedido. Nenhum
 * botão aqui fala com o cliente (isso pede OK do Lira).
 */
class LeadCheckoutController extends Controller
{
    private const PER_PAGE = 50;

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
                'valor_aberto' => round((float) (clone $base)->where('status', LeadCheckout::STATUS_ABERTO)->sum('subtotal'), 2),
            ],
            'sites' => collect(SiteAtual::SITES)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values(),
            'filters' => ['status' => $status, 'site' => $site, 'search' => $busca],
        ]);
    }
}
