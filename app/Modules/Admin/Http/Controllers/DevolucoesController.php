<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Modules\Marketplace\Support\ReturnsSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Devoluções — controle de tudo que a plataforma abriu de devolução ou
 * reclamação (pedido do usuário 2026-10-06): o que espera resposta nossa e
 * até quando, o que está voltando, o que chegou e falta conferir, o
 * veredito de quem conferiu (voltou certo, mau uso, faltando peças...) e,
 * principalmente, o que a plataforma encerrou sem o produto voltar.
 *
 * ML e Shopee vêm das APIs (ReturnsSyncService); TikTok e Amazon, sem API
 * disponível, entram pelo botão "Registrar devolução".
 */
class DevolucoesController extends Controller
{
    public const CANAIS = [
        'mercado_livre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'tiktok_shop' => 'TikTok Shop',
        'amazon' => 'Amazon',
        'loja' => 'Site',
    ];

    public function index(Request $request): Response
    {
        $aba = $request->string('aba')->toString() === 'todas' ? 'todas' : 'pendentes';
        $canal = array_key_exists((string) $request->string('canal'), self::CANAIS) ? (string) $request->string('canal') : null;
        $busca = trim((string) $request->string('busca')) ?: null;

        $casos = MarketplaceReturn::query()
            ->with(['order:id,shipping_name,total,stock_restored_at', 'order.items:id,order_id,product_id,product_name,quantity', 'events.user:id,name', 'receivedBy:id,name', 'verdictBy:id,name'])
            ->when($canal, fn ($q) => $q->where('channel', $canal))
            ->when($busca, fn ($q) => $q->where(fn ($q) => $q
                ->where('external_order_id', 'like', "%{$busca}%")
                ->orWhere('external_id', 'like', "%{$busca}%")
                ->orWhere('tracking_number', 'like', "%{$busca}%")
                ->orWhere('order_id', ctype_digit($busca) ? (int) $busca : 0)))
            ->when($aba === 'todas', fn ($q) => $q->where(fn ($q) => $q->where('opened_at', '>=', now()->subDays(180))->orWhereNull('opened_at')))
            ->orderByRaw('COALESCE(respond_due_at, receive_due_at, opened_at) asc')
            ->limit(500)
            ->get()
            ->map(fn (MarketplaceReturn $caso) => $this->linha($caso));

        if ($aba === 'pendentes') {
            $casos = $casos->filter(fn ($linha) => $linha['alertas'] !== [] || in_array($linha['situacao'], MarketplaceReturn::EM_ABERTO, true))->values();
        }

        $todosComAlerta = MarketplaceReturn::query()->whereIn('situacao', [...MarketplaceReturn::EM_ABERTO, MarketplaceReturn::ENCERRADA])->get();
        $chaves = $todosComAlerta->flatMap(fn ($caso) => collect($caso->alertas())->pluck('chave'));

        return Inertia::render('Admin/Devolucoes/Index', [
            'casos' => $casos,
            'filtros' => ['aba' => $aba, 'canal' => $canal, 'busca' => $busca],
            'resumo' => [
                'responder' => $todosComAlerta->where('situacao', MarketplaceReturn::AGUARDANDO_RESPOSTA)->count(),
                'prazo' => $chaves->filter(fn ($c) => in_array($c, ['prazo_24h', 'prazo_vencido'], true))->count(),
                'mediacao' => $todosComAlerta->where('situacao', MarketplaceReturn::EM_MEDIACAO)->count(),
                'voltando' => $todosComAlerta->whereIn('situacao', [MarketplaceReturn::AGUARDANDO_ENVIO, MarketplaceReturn::EM_TRANSITO])->count(),
                'conferir' => $chaves->filter(fn ($c) => $c === 'conferir')->count(),
                'semProduto' => $chaves->filter(fn ($c) => $c === 'sem_produto')->count(),
            ],
            'canais' => self::CANAIS,
            'situacoes' => MarketplaceReturn::SITUACOES,
            'vereditos' => MarketplaceReturn::VEREDITOS,
            'casoAberto' => $request->integer('caso') ?: null,
        ]);
    }

    /** Registro à mão — TikTok e Amazon não têm API de devolução disponível. */
    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'channel' => ['required', Rule::in(array_keys(self::CANAIS))],
            'pedido' => ['required', 'string', 'max:64'],
            'kind' => ['required', Rule::in([MarketplaceReturn::KIND_DEVOLUCAO, MarketplaceReturn::KIND_RECLAMACAO])],
            'reason_label' => ['required', 'string', 'max:255'],
            'situacao' => ['required', Rule::in(MarketplaceReturn::EM_ABERTO)],
            'tracking_number' => ['nullable', 'string', 'max:64'],
            'respond_due_at' => ['nullable', 'date'],
        ]);

        $pedido = Order::query()
            ->where(fn ($q) => $q->where('external_order_id', $dados['pedido'])->orWhere('id', ctype_digit($dados['pedido']) ? (int) $dados['pedido'] : 0))
            ->first();

        $caso = MarketplaceReturn::query()->create([
            'channel' => $dados['channel'],
            'external_id' => 'manual-'.now()->format('YmdHis').'-'.random_int(100, 999),
            'order_id' => $pedido?->id,
            'external_order_id' => $pedido?->external_order_id ?? $dados['pedido'],
            'kind' => $dados['kind'],
            'reason_label' => $dados['reason_label'],
            'situacao' => $dados['situacao'],
            'tracking_number' => $dados['tracking_number'] ?? null,
            'respond_due_at' => isset($dados['respond_due_at']) ? Carbon::parse($dados['respond_due_at'], config('app.timezone')) : null,
            'opened_at' => now(),
            'manual' => true,
        ]);

        $caso->events()->create([
            'situacao' => $caso->situacao,
            'description' => 'Registrada à mão: '.$caso->reason_label,
            'user_id' => $request->user()->id,
            'happened_at' => now(),
        ]);

        $this->limparContagem();

        return redirect("/admin/devolucoes?caso={$caso->id}")->with('success', 'Devolução registrada.'.($pedido ? '' : ' Pedido não encontrado no Kazakora — ficou só com o número informado.'));
    }

    /**
     * Ações da equipe sobre o caso: confirmar que o produto chegou, dar o
     * veredito, anotar, mudar a situação (só nos registrados à mão — os
     * de API seguem a plataforma) e devolver ao estoque.
     */
    public function update(Request $request, MarketplaceReturn $devolucao, OrderPaymentFinalizer $finalizer): RedirectResponse
    {
        $acao = $request->validate(['acao' => ['required', Rule::in(['receber', 'veredito', 'nota', 'situacao', 'estoque'])]])['acao'];
        $usuario = $request->user();
        $registrar = fn (string $texto) => $devolucao->events()->create([
            'situacao' => $devolucao->situacao, 'description' => $texto, 'user_id' => $usuario->id, 'happened_at' => now(),
        ]);

        switch ($acao) {
            case 'receber':
                $devolucao->update(['received_at' => now(), 'received_by' => $usuario->id, 'situacao' => $devolucao->verdict ? MarketplaceReturn::CONFERIDA : MarketplaceReturn::ENTREGUE]);
                $registrar('Produto recebido aqui na loja');
                break;

            case 'veredito':
                $dados = $request->validate([
                    'verdict' => ['required', Rule::in(array_keys(MarketplaceReturn::VEREDITOS))],
                    'verdict_note' => ['nullable', 'string', 'max:2000'],
                ]);
                $recebido = $dados['verdict'] !== 'nao_recebido';
                $devolucao->update([
                    ...$dados,
                    'verdict_at' => now(),
                    'verdict_by' => $usuario->id,
                    'received_at' => $recebido ? ($devolucao->received_at ?? now()) : null,
                    'received_by' => $recebido ? ($devolucao->received_by ?? $usuario->id) : null,
                    'situacao' => $recebido ? MarketplaceReturn::CONFERIDA : $devolucao->situacao,
                ]);
                $registrar('Veredito: '.MarketplaceReturn::VEREDITOS[$dados['verdict']].($dados['verdict_note'] ? " — {$dados['verdict_note']}" : ''));
                break;

            case 'nota':
                $registrar('Nota: '.$request->validate(['nota' => ['required', 'string', 'max:2000']])['nota']);
                break;

            case 'situacao':
                abort_unless($devolucao->manual, 422, 'A situação de casos da API segue a plataforma.');
                $situacao = $request->validate(['situacao' => ['required', Rule::in(array_keys(MarketplaceReturn::SITUACOES))]])['situacao'];
                $devolucao->update(['situacao' => $situacao, 'closed_at' => in_array($situacao, [MarketplaceReturn::ENCERRADA, MarketplaceReturn::CANCELADA], true) ? now() : null]);
                $registrar('Situação: '.MarketplaceReturn::SITUACOES[$situacao]);
                break;

            case 'estoque':
                if (! $devolucao->order) {
                    return back()->with('error', 'Esse caso não está ligado a um pedido do Kazakora — não tem estoque pra devolver.');
                }

                if ($devolucao->order->stock_restored_at) {
                    return back()->with('warning', 'O estoque desse pedido já tinha voltado em '.$devolucao->order->stock_restored_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i').'.');
                }

                $finalizer->restoreStockIfNeeded($devolucao->order, 'Devolução '.(self::CANAIS[$devolucao->channel] ?? $devolucao->channel)." — caso #{$devolucao->id}");
                $registrar('Produto devolvido ao estoque');
                break;
        }

        $this->limparContagem();

        return back()->with('success', 'Devolução atualizada.');
    }

    /** Reconsulta na hora (botão "Atualizar agora"). */
    public function sincronizar(ReturnsSyncService $sync): RedirectResponse
    {
        try {
            $total = $sync->sincronizar();
        } catch (Throwable $exception) {
            return back()->with('error', 'Não consegui atualizar agora: '.$exception->getMessage());
        }

        $this->limparContagem();

        return back()->with('success', "Atualizado: {$total['mercado_livre']} do Mercado Livre, {$total['shopee']} da Shopee.");
    }

    /** Número do menu lateral — casos com alguma pendência. */
    public static function contagemDeAlertas(): int
    {
        return (int) cache()->remember('devolucoes.contagem_alertas', 300, fn () => MarketplaceReturn::query()
            ->whereIn('situacao', [...MarketplaceReturn::EM_ABERTO, MarketplaceReturn::ENCERRADA])
            ->get()
            ->filter(fn (MarketplaceReturn $caso) => $caso->alertas() !== [])
            ->count());
    }

    private function limparContagem(): void
    {
        cache()->forget('devolucoes.contagem_alertas');
    }

    /** @return array<string, mixed> */
    private function linha(MarketplaceReturn $caso): array
    {
        return [
            'id' => $caso->id,
            'canal' => $caso->channel,
            'externo' => $caso->external_id,
            'manual' => $caso->manual,
            'pedidoId' => $caso->order_id,
            'pedidoExterno' => $caso->external_order_id,
            'cliente' => $caso->order?->shipping_name,
            'produtos' => $caso->order?->items->map(fn ($item) => "{$item->quantity}x {$item->product_name}")->implode(', '),
            'estoqueDevolvidoEm' => $caso->order?->stock_restored_at?->toIso8601String(),
            'tipo' => $caso->kind,
            'motivo' => $caso->reason_label ?? $caso->reason_code,
            'situacao' => $caso->situacao,
            'statusPlataforma' => $caso->platform_status,
            'statusEnvio' => $caso->return_status,
            'dinheiro' => $caso->money_status,
            'estornoNoEnvio' => $caso->refund_at === 'shipped',
            'valor' => $caso->refund_amount !== null ? (float) $caso->refund_amount : null,
            'rastreio' => $caso->tracking_number,
            'prazoResposta' => $caso->respond_due_at?->toIso8601String(),
            'prazoConferir' => $caso->receive_due_at?->toIso8601String(),
            'abertaEm' => $caso->opened_at?->toIso8601String(),
            'entregueEm' => $caso->delivered_at?->toIso8601String(),
            'encerradaEm' => $caso->closed_at?->toIso8601String(),
            'resolucao' => $caso->resolution,
            'recebidoEm' => $caso->received_at?->toIso8601String(),
            'recebidoPor' => $caso->receivedBy?->name,
            'veredito' => $caso->verdict,
            'vereditoNota' => $caso->verdict_note,
            'vereditoEm' => $caso->verdict_at?->toIso8601String(),
            'vereditoPor' => $caso->verdictBy?->name,
            'alertas' => $caso->alertas(),
            'historico' => $caso->events->take(30)->map(fn ($evento) => [
                'quando' => $evento->happened_at?->toIso8601String(),
                'texto' => $evento->description,
                'quem' => $evento->user?->name,
            ])->values(),
        ];
    }
}
