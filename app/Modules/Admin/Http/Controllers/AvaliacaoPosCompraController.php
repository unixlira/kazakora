<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catalog\Mail\PedidoAvaliacao;
use App\Modules\Catalog\Models\AvaliacaoSolicitacao;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\SolicitacaoAvaliacao;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Avaliações › Pedido de avaliação pós-compra (pedido do Lira 2026-10-18):
 * liga/desliga a rotina avaliacoes:solicitar, edita o e-mail e o cupom, mostra
 * a prévia e manda um teste para o próprio admin. Responde JSON para o painel
 * (SolicitacaoPosCompra.vue) funcionar dentro de qualquer tela de Avaliações.
 */
class AvaliacaoPosCompraController extends Controller
{
    public function show(): JsonResponse
    {
        $config = SolicitacaoAvaliacao::config();

        return response()->json([
            'config' => $config,
            'variaveis' => SolicitacaoAvaliacao::VARIAVEIS,
            'ativadoEm' => SolicitacaoAvaliacao::ativadoEm()?->toIso8601String(),
            'horario' => sprintf('%dh às %dh', SolicitacaoAvaliacao::HORA_INICIO, SolicitacaoAvaliacao::HORA_FIM),
            'resumo' => $this->resumo($config),
            'recentes' => AvaliacaoSolicitacao::query()
                ->with(['order:id,external_order_id,shipping_name,entregue_em', 'coupon' => fn ($q) => $q->comUsos()])
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (AvaliacaoSolicitacao $s) => [
                    'id' => $s->id,
                    'pedido' => $s->order?->numero,
                    'order_id' => $s->order_id,
                    'cliente' => $s->order?->shipping_name,
                    'email' => $s->email,
                    'status' => $s->status,
                    'erro' => $s->erro,
                    'cupom' => $s->coupon?->code,
                    'cupom_usado' => $s->coupon ? $s->coupon->totalUsos() > 0 : false,
                    'enviado_em' => $s->enviado_em?->toIso8601String(),
                    'avaliado_em' => $s->avaliado_em?->toIso8601String(),
                    'criado_em' => $s->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $config = SolicitacaoAvaliacao::salvar($this->validar($request));

        Log::info('Pedido de avaliação pós-compra alterado no admin', [
            'usuario' => $request->user()?->id,
            'ativo' => $config['ativo'],
            'cupom' => $config['cupom_ativo'] ? "{$config['cupom_tipo']} {$config['cupom_valor']}" : 'sem cupom',
        ]);

        return response()->json([
            'message' => $config['ativo']
                ? 'Salvo. A rotina está ligada e envia de hora em hora, das 9h às 20h.'
                : 'Salvo. A rotina está desligada: nenhum e-mail sai.',
            ...$this->show()->getData(true),
        ]);
    }

    /** Prévia com o texto que está no formulário (ainda sem salvar). */
    public function previa(Request $request): JsonResponse
    {
        $mail = $this->exemplo($this->validar($request), $request->user());

        return response()->json([
            'assunto' => SolicitacaoAvaliacao::preencher($mail->config['assunto'], SolicitacaoAvaliacao::valores($mail->order, $mail->coupon, $mail->config, $mail->produtos)),
            'html' => $mail->render(),
        ]);
    }

    /** Manda o exemplo para o e-mail do admin logado. Nada é gravado: cupom de exemplo. */
    public function teste(Request $request): JsonResponse
    {
        $mail = $this->exemplo($this->validar($request), $request->user());

        try {
            Mail::to($request->user()->email)->send($mail);
        } catch (Throwable $erro) {
            return response()->json(['message' => 'O e-mail de teste não saiu: '.$erro->getMessage()], 422);
        }

        return response()->json(['message' => "E-mail de teste enviado para {$request->user()->email}."]);
    }

    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'ativo' => ['required', 'boolean'],
            'dias_apos_entrega' => ['required', 'integer', 'min:0', 'max:60'],
            'assunto' => ['required', 'string', 'max:150'],
            'titulo' => ['required', 'string', 'max:120'],
            'mensagem' => ['required', 'string', 'max:3000'],
            'botao' => ['required', 'string', 'max:40'],
            'cupom_ativo' => ['required', 'boolean'],
            'cupom_tipo' => ['required', Rule::in([Coupon::TYPE_PERCENTAGE, Coupon::TYPE_FIXED])],
            'cupom_valor' => ['required_if:cupom_ativo,true', 'nullable', 'numeric', 'min:0', 'max:999'],
            'cupom_validade_dias' => ['required', 'integer', 'min:1', 'max:365'],
            'cupom_pedido_minimo' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'cupom_prefixo' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
        ], [
            'cupom_prefixo.regex' => 'O começo do código do cupom aceita só letras e números.',
        ]);

        if ($dados['cupom_ativo'] && $dados['cupom_tipo'] === Coupon::TYPE_PERCENTAGE && (float) $dados['cupom_valor'] > 50) {
            abort(response()->json(['message' => 'Desconto acima de 50% não é aceito aqui.', 'errors' => ['cupom_valor' => ['Desconto acima de 50% não é aceito aqui.']]], 422));
        }
        $dados['cupom_valor'] = (float) ($dados['cupom_valor'] ?? 0);
        $dados['cupom_pedido_minimo'] = filled($dados['cupom_pedido_minimo'] ?? null) ? (float) $dados['cupom_pedido_minimo'] : null;

        return $dados;
    }

    /** E-mail montado na memória: cliente = admin logado, produto = o último entregue (ou um ativo). */
    private function exemplo(array $config, User $admin): PedidoAvaliacao
    {
        $order = (new Order)->forceFill(['id' => 0, 'external_order_id' => '261018EXEMPLO', 'shipping_name' => $admin->name]);
        $order->setRelation('user', $admin);

        $ultimo = Order::query()->where('origin', Order::ORIGIN_STORE)->where('status', Order::STATUS_COMPLETED)
            ->latest('id')->with('items')->first();
        $ids = $ultimo?->items->pluck('product_id')->unique()->take(3)->all() ?: [];
        $produtos = Product::query()
            ->with(['images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('position')])
            ->when($ids, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->where('is_active', true)->whereHas('images')->latest('id')->limit(1))
            ->get()
            ->map(fn (Product $produto) => [
                'id' => $produto->id,
                'nome' => $produto->name,
                'imagem' => $produto->images->first()?->thumb_url,
                'link' => route('avaliacoes.avaliar', $produto->slug),
            ])->values()->all();

        $cupom = $config['cupom_ativo'] && $config['cupom_valor'] > 0
            ? (new Coupon)->forceFill([
                'code' => Coupon::normalizar($config['cupom_prefixo']).'EXEMPL',
                'discount_type' => $config['cupom_tipo'],
                'discount_value' => $config['cupom_valor'],
                'min_order_value' => $config['cupom_pedido_minimo'],
                'expires_at' => now()->addDays((int) $config['cupom_validade_dias'])->endOfDay(),
            ])
            : null;

        return new PedidoAvaliacao($order, $produtos, $cupom, $config);
    }

    private function resumo(array $config): array
    {
        $porStatus = AvaliacaoSolicitacao::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        $cupons = AvaliacaoSolicitacao::query()->whereNotNull('coupon_id')->pluck('coupon_id');

        return [
            'enviados' => (int) ($porStatus[AvaliacaoSolicitacao::STATUS_ENVIADO] ?? 0),
            'pulados' => (int) ($porStatus[AvaliacaoSolicitacao::STATUS_PULADO] ?? 0),
            'falhas' => (int) ($porStatus[AvaliacaoSolicitacao::STATUS_FALHOU] ?? 0),
            'avaliaram' => AvaliacaoSolicitacao::query()->whereNotNull('avaliado_em')->count(),
            'cupons_usados' => $cupons->isEmpty() ? 0 : Coupon::query()->whereIn('id', $cupons)
                ->whereHas('pedidos', fn ($q) => $q->whereIn('status', Coupon::STATUS_USO))->count(),
            'na_fila' => $config['ativo'] ? SolicitacaoAvaliacao::pedidosNaHora($config)->count() : 0,
        ];
    }
}
