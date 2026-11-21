<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\Sequencia\CalendarioSequencia;
use App\Modules\Cart\Support\Sequencia\ImportadorSequencia;
use App\Support\SiteAtual;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Carrinhos abandonados > Sequência (pedido do Lira 21/11/2026): só
 * leitura. Mostra cada carrinho na sequência de recuperação, os disparos
 * feitos, o próximo e por que parou. Quem manda é o comando
 * loja:carrinho-abandonado-sequencia.
 */
class CarrinhoSequenciaController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $status = in_array($request->string('status')->toString(), ['ativo', 'parado'], true) ? $request->string('status')->toString() : null;
        $site = in_array($request->string('site')->toString(), ImportadorSequencia::SITES, true) ? $request->string('site')->toString() : null;
        $busca = trim($request->string('search')->toString());

        $registros = Registro::query()
            ->with('produto:id,name')
            ->when($status === 'ativo', fn ($q) => $q->where('parado', false))
            ->when($status === 'parado', fn ($q) => $q->where('parado', true))
            ->when($site, fn ($q) => $q->where('site', $site))
            ->when($busca !== '', function ($q) use ($busca) {
                $digitos = preg_replace('/\D/', '', $busca);
                $q->where(fn ($q) => $q
                    ->where('nome', 'like', "%{$busca}%")
                    ->orWhere('email', 'like', '%'.mb_strtolower($busca).'%')
                    ->when(strlen($digitos) >= 4, fn ($q) => $q->orWhere('telefone', 'like', "%{$digitos}%")));
            })
            ->latest('abandonado_em')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Registro $r) => [
                'id' => $r->id,
                'site' => $r->site,
                'origem' => $r->origem,
                'nome' => $r->nome,
                'email' => $r->email,
                'telefone' => $r->telefone,
                'produto' => $r->produto?->name ?? (collect($r->itens ?? [])->firstWhere('product_id', $r->produto_id)['nome'] ?? null),
                'subtotal' => (float) $r->subtotal,
                'disparos' => $r->disparos_enviados,
                'total_disparos' => CalendarioSequencia::TOTAL,
                'proximo' => $r->proximo_disparo_em?->toIso8601String(),
                'ultimo' => $r->ultimo_disparo_em?->toIso8601String(),
                'abandonado_em' => $r->abandonado_em?->toIso8601String(),
                'parado' => $r->parado,
                'motivo' => $r->motivo_parada,
                'historico' => collect($r->historico ?? [])->take(-12)->values(),
            ]);

        $base = Registro::query()->when($site, fn ($q) => $q->where('site', $site));

        return Inertia::render('Admin/CarrinhoSequencia/Index', [
            'registros' => $registros,
            'resumo' => [
                'ativos' => (clone $base)->where('parado', false)->count(),
                'compraram' => (clone $base)->where('motivo_parada', Registro::PAROU_COMPROU)->count(),
                'pediram_parar' => (clone $base)->where('motivo_parada', Registro::PAROU_PEDIU)->count(),
                'na_hora' => (clone $base)->where('parado', false)->where('proximo_disparo_em', '<=', now())->count(),
            ],
            'sites' => collect(ImportadorSequencia::SITES)->map(fn ($id) => ['id' => $id, 'nome' => SiteAtual::SITES[$id] ?? $id])->values(),
            'filters' => ['status' => $status, 'site' => $site, 'search' => $busca],
        ]);
    }
}
