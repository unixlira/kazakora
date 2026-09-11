<?php

namespace App\Modules\Fiscal\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * O carrinho (pack) do Mercado Livre visto pelo lado fiscal: uma NF-e só pra
 * todos os pedidos do carrinho.
 *
 * BUG REAL 2026-09-11 (pack 2000014906196989, pedidos #1588/#1589): a
 * compradora fez UMA compra com 2 anúncios, o ML guarda isso como 2 pedidos
 * ligados pelo pack_id, e o Kazakora emitia uma nota por pedido. O envio do
 * pack exige uma nota no valor do carrinho — o ML recusou as duas, a venda
 * ficou 4 dias sem etiqueta e as duas notas passaram do prazo de
 * cancelamento contando em dobro no faturamento.
 *
 * A nota fica no pedido TITULAR (o de menor id entre os não cancelados, ou o
 * que já tem nota) e é montada com os itens e o valor de todos os pedidos
 * ativos do carrinho. Os outros pedidos não têm nota própria: são cobertos
 * pela do titular.
 *
 * Tudo aqui é leitura do banco local. Conferir com a API do ML se o carrinho
 * está completo é do MercadoLivrePackInvoiceGate.
 */
class PackDoPedido
{
    /**
     * Status de nota que já ocupa o carrinho. EXTERNAL fica de fora: nunca
     * foi NF-e de verdade (serie=0), e o issue() converte a linha.
     */
    private const STATUS_QUE_OCUPAM = [
        Invoice::STATUS_PENDING,
        Invoice::STATUS_SIGNED,
        Invoice::STATUS_SENT,
        Invoice::STATUS_AUTHORIZED,
        Invoice::STATUS_REJECTED,
        Invoice::STATUS_DENIED,
        Invoice::STATUS_ERROR,
    ];

    /**
     * Todos os pedidos do carrinho, o próprio incluído, cancelados também.
     *
     * @return EloquentCollection<int, Order>
     */
    public function pedidos(Order $order): EloquentCollection
    {
        if (! $order->channel_pack_id || $order->origin !== Order::ORIGIN_MERCADO_LIVRE) {
            return new EloquentCollection([$order]);
        }

        return Order::query()
            ->with(['items', 'invoice'])
            ->where('origin', $order->origin)
            ->where('channel_pack_id', $order->channel_pack_id)
            ->orderBy('id')
            ->get();
    }

    /** @return EloquentCollection<int, Order> */
    public function ativos(Order $order): EloquentCollection
    {
        return $this->pedidos($order)
            ->reject(fn (Order $pedido) => $pedido->status === Order::STATUS_CANCELLED)
            ->values();
    }

    /** Carrinho de verdade: mais de um pedido ativo. */
    public function ehCarrinho(Order $order): bool
    {
        return $this->ativos($order)->count() > 1;
    }

    /** @return Collection<int, Order> */
    public function comNota(Order $order): Collection
    {
        return $this->pedidos($order)
            ->filter(fn (Order $pedido) => in_array($pedido->invoice?->status, self::STATUS_QUE_OCUPAM, true))
            ->values();
    }

    /**
     * Quem carrega a nota do carrinho, ou null quando não dá pra decidir
     * sozinho (ver motivoDeBloqueio()).
     */
    public function titular(Order $order): ?Order
    {
        if ($this->motivoDeBloqueio($order) !== null) {
            return null;
        }

        return $this->comNota($order)->first() ?? $this->ativos($order)->first();
    }

    /**
     * Situações em que emitir automático duplicaria ou erraria a nota. Quem
     * resolve é gente (contador), então o sistema para e avisa.
     */
    public function motivoDeBloqueio(Order $order): ?string
    {
        if (! $this->ehCarrinho($order)) {
            return null;
        }

        // Nota cancelada dentro de um carrinho ativo = carrinho que alguém já
        // resolveu na mão (ensaio de 2026-09-11: #1367/#1368 e #1540/#1541
        // tiveram as notas separadas canceladas e a do carrinho emitida à
        // parte, e o #1588/#1589 ficou assim também). Emitir de novo
        // automático duplicaria a nota que já existe fora deste pedido.
        $comNotaCancelada = $this->pedidos($order)->filter(fn (Order $pedido) => $pedido->invoice?->status === Invoice::STATUS_CANCELLED);

        if ($comNotaCancelada->isNotEmpty()) {
            return 'Carrinho do Mercado Livre com NF-e cancelada (#'
                .$comNotaCancelada->pluck('id')->implode(', #')
                .') — já foi tratado à mão; conferir a nota do carrinho antes de emitir outra.';
        }

        $comNota = $this->comNota($order);

        if ($comNota->count() > 1) {
            return 'Carrinho do Mercado Livre com NF-e separadas em mais de um pedido (#'
                .$comNota->pluck('id')->implode(', #')
                .'). Emitir a nota do carrinho por cima duplicaria o faturamento — resolver com o contador.';
        }

        $dono = $comNota->first();

        if (! $dono) {
            return null;
        }

        if ($dono->status === Order::STATUS_CANCELLED) {
            return "A NF-e deste carrinho está no pedido #{$dono->id}, que foi cancelado. Resolver com o contador antes de emitir outra.";
        }

        $totalDoCarrinho = round($this->ativos($order)->sum(fn (Order $pedido) => (float) $pedido->total), 2);

        if ($dono->invoice->status === Invoice::STATUS_AUTHORIZED
            && round((float) $dono->invoice->valor_total, 2) !== $totalDoCarrinho) {
            return "A NF-e autorizada do pedido #{$dono->id} (R$ {$dono->invoice->valor_total}) não cobre o carrinho inteiro (R$ {$totalDoCarrinho}). Resolver com o contador.";
        }

        return null;
    }

    /**
     * Outro pedido do carrinho que já carrega a nota — este pedido não emite
     * a sua.
     */
    public function cobertoPor(Order $order): ?Order
    {
        if (! $this->ehCarrinho($order)) {
            return null;
        }

        return $this->comNota($order)->first(fn (Order $pedido) => $pedido->id !== $order->id);
    }

    /**
     * O pedido como a NF-e deve enxergá-lo: se ele é o titular de um
     * carrinho, os itens e valores de todos os pedidos ativos; senão, ele
     * mesmo.
     *
     * Devolve um modelo NÃO persistido (replicate) com o id do titular — os
     * caminhos de arquivo e o order_id da nota continuam os do titular, e um
     * save() acidental falharia na chave primária em vez de gravar o total
     * do carrinho no pedido.
     *
     * Pedido de carrinho que NÃO é o titular (ou carrinho bloqueado) lança
     * em vez de seguir: emitir a nota só dele é exatamente o defeito que
     * isto existe pra impedir. O GenerateInvoiceJob já filtra antes; isto é
     * a última barreira pra qualquer outro caminho que chegue no issue().
     */
    public function pedidoFiscal(Order $order): Order
    {
        if ($order->origin !== Order::ORIGIN_MERCADO_LIVRE || ! $order->channel_pack_id || ! $this->ehCarrinho($order)) {
            return $order;
        }

        if ($motivo = $this->motivoDeBloqueio($order)) {
            throw new RuntimeException($motivo);
        }

        $titular = $this->titular($order);

        if (! $titular || $titular->id !== $order->id) {
            throw new RuntimeException("Pedido #{$order->id} é de um carrinho do Mercado Livre cuja NF-e sai no pedido #{$titular?->id} — não emite nota própria.");
        }

        $ativos = $this->ativos($order);

        $fiscal = $order->replicate();
        $fiscal->id = $order->id;
        $fiscal->setRelation('invoice', $order->invoice);
        $fiscal->setRelation('items', new EloquentCollection($ativos->flatMap(fn (Order $pedido) => $pedido->items)->values()->all()));

        foreach (['subtotal', 'shipping_cost', 'discount_amount', 'total'] as $campo) {
            $fiscal->{$campo} = round($ativos->sum(fn (Order $pedido) => (float) $pedido->{$campo}), 2);
        }

        return $fiscal;
    }

    /**
     * Aviso pra quem cancela um pedido de carrinho. A nota é do carrinho
     * inteiro: cancelar ela derrubaria a nota dos itens que continuam
     * vendidos, então nunca é cancelada daqui.
     */
    public function avisoDeCancelamento(Order $order): ?string
    {
        if (! $order->channel_pack_id || $order->origin !== Order::ORIGIN_MERCADO_LIVRE) {
            return null;
        }

        $outrosAtivos = $this->ativos($order)->reject(fn (Order $pedido) => $pedido->id === $order->id);

        if ($outrosAtivos->isEmpty()) {
            return null;
        }

        $dono = $this->comNota($order)->first(fn (Order $pedido) => $pedido->invoice?->status === Invoice::STATUS_AUTHORIZED);

        if (! $dono) {
            return null;
        }

        return "Pedido cancelado, mas a NF-e nº {$dono->invoice->numero} é do carrinho inteiro e cobre também o(s) pedido(s) #"
            .$outrosAtivos->pluck('id')->implode(', #')
            .' — não foi cancelada. Tratar com o contador (devolução parcial).';
    }
}
