<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedidos › Pedidos Beauty Kora (pedido 2026-10-12): o que os clientes
 * compraram no site Beauty Kora (gravado por /api/beautykora/pedidos).
 * Fica fora de orders: não mexe em estoque, nota nem margem da KazaKora.
 */
class BeautyKoraPedidoController extends Controller
{
    private const POR_PAGINA = 50;

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'status' => ['nullable', Rule::in(BeautyKoraPedido::STATUS)],
            'busca' => ['nullable', 'string', 'max:100'],
        ]);
        $busca = trim((string) ($filtros['busca'] ?? ''));

        $query = BeautyKoraPedido::query()
            ->with('cliente:id,name,email')
            ->when($filtros['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($busca !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('produto_nome', 'like', '%'.addcslashes($busca, '\\%_').'%')
                ->orWhereHas('cliente', fn (Builder $c) => $c
                    ->where('name', 'like', '%'.addcslashes($busca, '\\%_').'%')
                    ->orWhere('email', 'like', '%'.addcslashes($busca, '\\%_').'%'))));

        $totais = (clone $query)->toBase()
            ->selectRaw('COUNT(*) as itens, COALESCE(SUM(valor_pago_centavos), 0) as centavos, COUNT(DISTINCT cliente_id) as clientes')
            ->first();

        $pedidos = $query->latest('created_at')->latest('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (BeautyKoraPedido $p) => [
                'id' => $p->id,
                'quando' => $p->created_at?->format('d/m/Y H:i'),
                'cliente' => $p->cliente?->name,
                'email' => $p->cliente?->email,
                'produto' => $p->produto_nome,
                'imagem' => $p->produto_imagem,
                'quantidade' => $p->quantidade,
                'valor' => $p->valor_pago_centavos / 100,
                'status' => $p->status,
                'hostinger' => $p->hostinger_numero,
                'pago_em' => $p->pago_em?->format('d/m/Y H:i'),
                'cidade' => $p->endereco ? trim(($p->endereco['cidade'] ?? '').'/'.($p->endereco['estado'] ?? ''), '/') : null,
            ]);

        return Inertia::render('Admin/PedidosBeautyKora/Index', [
            'pedidos' => $pedidos,
            'totais' => [
                'itens' => (int) ($totais->itens ?? 0),
                'valor' => ((int) ($totais->centavos ?? 0)) / 100,
                'clientes' => (int) ($totais->clientes ?? 0),
            ],
            'filtros' => ['status' => $filtros['status'] ?? '', 'busca' => $busca],
            'statusOpcoes' => BeautyKoraPedido::STATUS,
        ]);
    }

    public function status(Request $request, BeautyKoraPedido $pedido): RedirectResponse
    {
        $dados = $request->validate(['status' => ['required', Rule::in(BeautyKoraPedido::STATUS)]]);
        $antes = $pedido->status;
        $pedido->update($dados);

        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'action' => AuditLog::ACTION_UPDATE,
            'entity' => 'BeautyKoraPedido',
            'entity_id' => $pedido->id,
            'old_values' => ['status' => $antes],
            'new_values' => ['status' => $pedido->status],
            'created_at' => now(),
        ]);

        return back()->with('success', "Pedido #{$pedido->id} agora está como {$pedido->status}.");
    }
}
