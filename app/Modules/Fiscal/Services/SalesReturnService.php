<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Devolução de venda pra pessoa física (orientação do contador, 2026-10-08):
 * o cliente PF não emite nota de devolução, então ele assina uma declaração
 * de devolução e a loja emite uma NF-e de ENTRADA (CFOP 1202 dentro de SP,
 * 2202 fora) referenciando a nota de venda. O XML sai do NFeXmlBuilderService
 * (sales_return: tpNF=0, finNFe=4, refNFe, CSOSN 900).
 *
 * Também é o caminho quando a nota passou das 480h e não dá mais pra
 * cancelar.
 */
class SalesReturnService
{
    /** Marketplaces que já emitem a devolução sozinhos: nota 2567 (set/2026) foi cancelada por duplicidade com o TikTok. */
    public const CANAIS_QUE_EMITEM_DEVOLUCAO = [Order::ORIGIN_TIKTOK_SHOP];

    public function __construct(
        private readonly StockManager $stock,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {}

    /**
     * Quanto de cada item da venda ainda pode voltar (vendido menos o que já
     * tem devolução emitida ou em emissão).
     *
     * @return Collection<int, array{id: int, product_id: ?int, nome: string, preco: float, vendido: int, devolvido: int, disponivel: int}>
     */
    public function itensDevolviveis(Invoice $venda): Collection
    {
        $venda->loadMissing('order.items');
        $jaDevolvido = $this->jaDevolvido($venda);

        return ($venda->order?->items ?? collect())->map(function (OrderItem $item) use ($jaDevolvido) {
            $devolvido = (int) ($jaDevolvido[$item->product_id ?? 'nome:'.$item->product_name] ?? 0);

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'nome' => $item->product_name,
                'preco' => (float) $item->product_price,
                'vendido' => (int) $item->quantity,
                'devolvido' => min($devolvido, (int) $item->quantity),
                'disponivel' => max(0, (int) $item->quantity - $devolvido),
            ];
        })->values();
    }

    /** Por que esta nota não aceita devolução (null = aceita). */
    public function impedimento(Invoice $venda): ?string
    {
        $order = $venda->order;

        return match (true) {
            $venda->status !== Invoice::STATUS_AUTHORIZED => 'Só nota de venda autorizada aceita devolução.',
            ! $order => 'Nota sem pedido no Kazakora: emita a devolução pelo emissor de origem.',
            in_array($order->fiscal_operation_type, ['sales_return', 'purchase_return'], true) => 'Esta já é uma nota de devolução.',
            strlen(preg_replace('/\D/', '', (string) $venda->chave_acesso)) !== 44 => 'Nota sem chave de acesso: a devolução precisa referenciar a chave da venda.',
            default => null,
        };
    }

    /**
     * @param  array<int|string, int|string>  $quantidades  order_item_id => quantidade devolvida
     */
    public function registrar(Invoice $venda, array $quantidades, string $motivo, bool $voltaAoEstoque, ?UploadedFile $declaracao = null): Order
    {
        if ($impedimento = $this->impedimento($venda)) {
            throw new RuntimeException($impedimento);
        }

        $itens = $this->itensDevolviveis($venda)->keyBy('id');
        $escolhidos = collect($quantidades)
            ->map(fn ($quantidade) => (int) $quantidade)
            ->filter(fn (int $quantidade) => $quantidade > 0);

        if ($escolhidos->isEmpty()) {
            throw new RuntimeException('Informe a quantidade devolvida de pelo menos um item.');
        }

        foreach ($escolhidos as $itemId => $quantidade) {
            $item = $itens->get((int) $itemId) ?? throw new RuntimeException('Item não pertence a esta nota.');

            if ($quantidade > $item['disponivel']) {
                throw new RuntimeException("{$item['nome']}: só {$item['disponivel']} unidade(s) ainda podem ser devolvidas.");
            }
        }

        return DB::transaction(function () use ($venda, $escolhidos, $motivo, $voltaAoEstoque, $declaracao) {
            $original = $venda->order->loadMissing('items');
            $originais = $original->items->keyBy('id');

            $subtotal = round($escolhidos->sum(fn ($quantidade, $itemId) => (float) $originais[$itemId]->product_price * $quantidade), 2);
            // Desconto da venda na mesma proporção: a entrada tem que bater
            // com o valor que saiu na nota de venda.
            $desconto = (float) $original->subtotal > 0
                ? round((float) $original->discount_amount * $subtotal / (float) $original->subtotal, 2)
                : 0.0;

            $order = Order::query()->create([
                'user_id' => $original->user_id,
                'status' => Order::STATUS_COMPLETED,
                'origin' => Order::ORIGIN_SALES_RETURN_INVOICE,
                'fiscal_operation_type' => 'sales_return',
                // Explícito: a coluna nasce com 1 (normal) e o XML usaria ela.
                'fiscal_finality' => 4,
                'fiscal_referenced_nfe_key' => $venda->chave_acesso,
                'fiscal_additional_info' => "Devolução de venda referente à NF-e nº {$venda->numero} série {$venda->serie}, chave {$venda->chave_acesso}. "
                    ."Destinatário pessoa física, sem emissão de nota própria: declaração de devolução arquivada. Motivo: {$motivo}",
                'buyer_document' => $original->buyer_document,
                'buyer_state_registration' => $original->buyer_state_registration,
                'buyer_taxpayer_type' => $original->buyer_taxpayer_type,
                'shipping_name' => $original->shipping_name,
                'shipping_phone' => $original->shipping_phone ?? '',
                'shipping_email' => $original->shipping_email,
                'shipping_zip' => $original->shipping_zip,
                'shipping_street' => $original->shipping_street,
                'shipping_number' => $original->shipping_number,
                'shipping_complement' => $original->shipping_complement,
                'shipping_neighborhood' => $original->shipping_neighborhood,
                'shipping_city' => $original->shipping_city,
                'shipping_state' => $original->shipping_state,
                'subtotal' => $subtotal,
                'shipping_cost' => 0,
                'discount_amount' => $desconto,
                'total' => round($subtotal - $desconto, 2),
            ]);

            foreach ($escolhidos as $itemId => $quantidade) {
                $item = $originais[$itemId];

                $order->items()->create([
                    ...collect($item->only([
                        'product_id', 'product_name', 'item_type', 'ncm', 'cest', 'cfop', 'cfop_outros_estados', 'origem_mercadoria', 'gtin',
                        'unidade_tributavel', 'icms_situacao_tributaria', 'pis_situacao_tributaria', 'pis_aliquota',
                        'cofins_situacao_tributaria', 'cofins_aliquota', 'percentual_aproximado_tributos',
                    ]))->reject(fn ($valor) => $valor === null)->all(),
                    'product_price' => $item->product_price,
                    'quantity' => $quantidade,
                    'subtotal' => round((float) $item->product_price * $quantidade, 2),
                ]);

                if ($voltaAoEstoque && $item->product) {
                    $this->stock->adjust($item->product, $quantidade, StockMovement::TYPE_RETURN, reason: "Devolução da NF-e {$venda->numero}/{$venda->serie}", reference: $order);
                }
            }

            if ($declaracao) {
                $order->update(['return_declaration_path' => $declaracao->storeAs("devolucoes/{$order->id}", 'declaracao-devolucao.'.($declaracao->extension() ?: 'pdf'), 'local')]);
            }

            $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, "Devolução da NF-e {$venda->numero}/{$venda->serie} (pedido #{$original->id}) registrada pelo admin");

            GenerateInvoiceJob::dispatch($order->id)->afterCommit();

            return $order;
        });
    }

    /**
     * Quantidades já devolvidas desta venda, por produto (ou nome, pra item
     * sem produto). Devolução cancelada/rejeitada não conta.
     *
     * @return array<int|string, int>
     */
    private function jaDevolvido(Invoice $venda): array
    {
        return Order::query()
            ->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)
            ->where('fiscal_referenced_nfe_key', $venda->chave_acesso)
            ->where(fn ($query) => $query
                ->whereDoesntHave('invoice')
                ->orWhereHas('invoice', fn ($invoice) => $invoice->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REJECTED, Invoice::STATUS_DENIED])))
            ->with('items:id,order_id,product_id,product_name,quantity')
            ->get()
            ->flatMap->items
            ->groupBy(fn (OrderItem $item) => $item->product_id ?? 'nome:'.$item->product_name)
            ->map(fn ($itens) => (int) $itens->sum('quantity'))
            ->all();
    }
}
