<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Lead;
use App\Support\SiteAtual;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Leads (pedido do Lira 2026-11-06): o que o cliente está digitando
 * no checkout (nome, CPF, e-mail, telefone, CEP) e o produto, para
 * acompanhar os abandonos. A tela se atualiza sozinha (recarga parcial do
 * Inertia a cada poucos segundos). Só leitura: nada vai ao cliente daqui.
 */
class LeadsController extends Controller
{
    private const PER_PAGE = 50;

    /** Atividade nos últimos minutos = "no checkout agora". */
    private const MINUTOS_AGORA = 5;

    public function index(Request $request): Response
    {
        $status = in_array($request->string('status')->toString(), [Lead::STATUS_ABERTO, Lead::STATUS_CONVERTIDO], true)
            ? $request->string('status')->toString()
            : null;
        $site = isset(SiteAtual::SITES[$request->string('site')->toString()]) ? $request->string('site')->toString() : null;
        $busca = trim($request->string('search')->toString());

        $leads = Lead::query()
            ->with('order:id,external_order_id,status')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($site, fn ($q) => $q->where('site', $site))
            ->when($busca !== '', function ($q) use ($busca) {
                $digitos = preg_replace('/\D/', '', $busca);
                $q->where(fn ($q) => $q
                    ->where('nome', 'like', "%{$busca}%")
                    ->orWhere('email', 'like', '%'.mb_strtolower($busca).'%')
                    ->when(strlen($digitos) >= 4, fn ($q) => $q
                        ->orWhere('cpf', 'like', "%{$digitos}%")
                        ->orWhere('telefone', 'like', "%{$digitos}%")
                        ->orWhere('cep', 'like', "%{$digitos}%")));
            })
            ->latest('updated_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Lead $lead) => [
                'id' => $lead->id,
                'site' => $lead->site,
                'nome' => $lead->nome,
                'email' => $lead->email,
                'cpf' => $lead->cpf,
                'telefone' => $lead->telefone,
                'cep' => $lead->cep,
                'ultimo_campo' => $lead->ultimo_campo,
                'itens' => $lead->itens ?? [],
                'quantidade_itens' => $lead->quantidade_itens,
                'subtotal' => (float) $lead->subtotal,
                'status' => $lead->status,
                'origem' => $lead->origem,
                'utm_source' => $lead->utm_source,
                'agora' => $lead->status === Lead::STATUS_ABERTO && $lead->updated_at?->gte(now()->subMinutes(self::MINUTOS_AGORA)),
                'pedido' => $lead->order ? ['id' => $lead->order->id, 'numero' => $lead->order->external_order_id] : null,
                'criado_em' => $lead->created_at?->toIso8601String(),
                'atualizado_em' => $lead->updated_at?->toIso8601String(),
            ]);

        $base = Lead::query()->when($site, fn ($q) => $q->where('site', $site));

        return Inertia::render('Admin/Leads/Index', [
            'leads' => $leads,
            'resumo' => [
                'agora' => (clone $base)->where('status', Lead::STATUS_ABERTO)->where('updated_at', '>=', now()->subMinutes(self::MINUTOS_AGORA))->count(),
                'abertos' => (clone $base)->where('status', Lead::STATUS_ABERTO)->count(),
                'convertidos' => (clone $base)->where('status', Lead::STATUS_CONVERTIDO)->count(),
                'valor_aberto' => round((float) (clone $base)->where('status', Lead::STATUS_ABERTO)->sum('subtotal'), 2),
                'atualizado_em' => now()->toIso8601String(),
            ],
            'sites' => collect(SiteAtual::SITES)->map(fn ($nome, $id) => ['id' => $id, 'nome' => $nome])->values(),
            'filters' => ['status' => $status, 'site' => $site, 'search' => $busca],
        ]);
    }
}
