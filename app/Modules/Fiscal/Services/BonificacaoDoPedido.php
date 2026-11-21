<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\NotaDoBrinde;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Nota de bonificação do brinde físico (pedido do Lira em 21/11/2026).
 *
 * Oferta com brinde que vai na caixa (ex.: Livro Falante + Lousa LED) sai em
 * duas NF-e: a de venda (só o que foi vendido) e, depois dela autorizada,
 * uma de bonificação com o brinde a R$ 0,00. Regras do Lira e do contador:
 *  - CFOP 5910 (mesmo estado) / 6910 (outro estado), CSOSN 102, sem pagamento;
 *  - valor zero; se a SEFAZ recusar o zero, sai de novo com R$ 0,01 por unidade;
 *  - autorizada a de bonificação, o cliente recebe um e-mail com as duas.
 *
 * O brinde é configurado no produto (Product::brinde_fisico), no admin, para
 * qualquer oferta. Produto variação usa o brinde do produto pai.
 */
class BonificacaoDoPedido
{
    public const CFOP = '5910';

    public const CFOP_OUTROS_ESTADOS = '6910';

    public const CSOSN = '102';

    public const NATUREZA = 'Remessa em bonificação';

    public const NCM_PADRAO = '95030099';

    /** Valor simbólico quando a SEFAZ recusa o item a R$ 0,00. */
    public const VALOR_SIMBOLICO = 0.01;

    public function __construct(private readonly OrderFulfillmentTimeline $timeline)
    {
    }

    /** Chamado pelo GenerateInvoiceJob depois de cada emissão. Nunca derruba a nota de venda. */
    public function depoisDaNota(Order $order, Invoice $invoice): void
    {
        try {
            if ($order->origin === Order::ORIGIN_STORE && $invoice->status === Invoice::STATUS_AUTHORIZED) {
                $this->criarPara($order);
            } elseif ($order->origin === Order::ORIGIN_BONUS_INVOICE) {
                if ($invoice->status === Invoice::STATUS_AUTHORIZED) {
                    $this->enviarEmail($order);
                } elseif (in_array($invoice->status, [Invoice::STATUS_REJECTED, Invoice::STATUS_ERROR], true)) {
                    $this->tentarComValorSimbolico($order);
                }
            }
        } catch (Throwable $e) {
            Log::warning('nfe.bonificacao_falhou', ['order_id' => $order->id, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Brindes físicos dos itens do pedido: [nome, quantidade, ncm, origem, item de origem].
     *
     * @return list<array{nome: string, quantidade: int, ncm: string, origem: int, item: OrderItem}>
     */
    public function brindesDo(Order $order): array
    {
        $order->loadMissing('items.product.parent', 'items.product.fiscalData');
        $brindes = [];

        foreach ($order->items as $item) {
            $produto = $item->product;
            $config = $produto?->brinde_fisico ?: $produto?->parent?->brinde_fisico;
            $nome = trim((string) ($config['nome'] ?? ''));

            if (! $produto || $nome === '' || $item->item_type === OrderItem::TYPE_SERVICE) {
                continue;
            }

            $ncm = preg_replace('/\D/', '', (string) ($config['ncm'] ?? '')) ?: self::NCM_PADRAO;
            $brindes[] = [
                'nome' => $nome,
                'quantidade' => max(1, (int) ($config['quantidade'] ?? 1)) * max(1, (int) $item->quantity),
                'ncm' => $ncm,
                'origem' => (int) ($config['origem'] ?? $produto->fiscalData?->origem ?? 0),
                'item' => $item,
            ];
        }

        return $brindes;
    }

    /** Cria (uma vez só) o pedido fiscal de bonificação e manda emitir. null = sem brinde físico. */
    public function criarPara(Order $venda): ?Order
    {
        $venda->loadMissing('invoice');
        $chave = $venda->invoice?->chave_acesso;
        $brindes = $this->brindesDo($venda);

        if (! $chave || $brindes === []) {
            return null;
        }

        if ($existente = $this->daVenda($venda)) {
            return $existente;
        }

        return DB::transaction(function () use ($venda, $chave, $brindes) {
            $bonus = Order::query()->create([
                'user_id' => $venda->user_id,
                'status' => Order::STATUS_COMPLETED,
                'origin' => Order::ORIGIN_BONUS_INVOICE,
                'site_origem' => $venda->site_origem,
                'fiscal_operation_type' => 'bonificacao',
                'fiscal_nature_operation' => self::NATUREZA,
                'fiscal_finality' => 1,
                'fiscal_referenced_nfe_key' => $chave,
                'fiscal_additional_info' => "Bonificação (brinde) referente ao pedido #{$venda->id} e à NF-e de venda nº {$venda->invoice->numero} série {$venda->invoice->serie}, chave {$chave}. Sem cobrança.",
                'buyer_document' => $venda->buyer_document,
                'buyer_state_registration' => $venda->buyer_state_registration,
                'buyer_taxpayer_type' => $venda->buyer_taxpayer_type,
                'shipping_name' => $venda->shipping_name,
                'shipping_phone' => $venda->shipping_phone ?? '',
                'shipping_email' => $venda->shipping_email,
                'shipping_zip' => $venda->shipping_zip,
                'shipping_street' => $venda->shipping_street,
                'shipping_number' => $venda->shipping_number,
                'shipping_complement' => $venda->shipping_complement,
                'shipping_neighborhood' => $venda->shipping_neighborhood,
                'shipping_city' => $venda->shipping_city,
                'shipping_state' => $venda->shipping_state,
                'subtotal' => 0,
                'shipping_cost' => 0,
                'discount_amount' => 0,
                'total' => 0,
            ]);

            foreach ($brindes as $brinde) {
                $fiscal = $brinde['item']->product?->fiscalData;
                $bonus->items()->create([
                    'product_id' => null,
                    'product_name' => $brinde['nome'],
                    'item_type' => OrderItem::TYPE_PRODUCT,
                    'product_price' => 0,
                    'quantity' => $brinde['quantidade'],
                    'subtotal' => 0,
                    'ncm' => $brinde['ncm'],
                    'cfop' => self::CFOP,
                    'cfop_outros_estados' => self::CFOP_OUTROS_ESTADOS,
                    'origem_mercadoria' => $brinde['origem'],
                    'unidade_tributavel' => 'UN',
                    'icms_situacao_tributaria' => self::CSOSN,
                    'pis_situacao_tributaria' => $fiscal?->pis_situacao_tributaria ?: '08',
                    'pis_aliquota' => 0,
                    'cofins_situacao_tributaria' => $fiscal?->cofins_situacao_tributaria ?: '08',
                    'cofins_aliquota' => 0,
                    'percentual_aproximado_tributos' => 0,
                ]);
            }

            $this->timeline->record($venda, OrderFulfillmentEvent::STEP_INVOICE_ISSUED, OrderFulfillmentEvent::STATUS_SUCCESS, "Nota de bonificação do brinde aberta no pedido fiscal #{$bonus->id}");
            GenerateInvoiceJob::dispatch($bonus->id)->afterCommit();

            return $bonus;
        });
    }

    /** Pedido de bonificação de uma venda (pela chave da nota de venda). */
    public function daVenda(Order $venda): ?Order
    {
        $chave = $venda->invoice?->chave_acesso;

        return $chave ? Order::query()->where('origin', Order::ORIGIN_BONUS_INVOICE)->where('fiscal_referenced_nfe_key', $chave)->first() : null;
    }

    /** Venda de uma bonificação. */
    public function vendaDa(Order $bonus): ?Order
    {
        $venda = Invoice::query()->where('chave_acesso', $bonus->fiscal_referenced_nfe_key)->first()?->order;

        return $venda instanceof Order ? $venda : null;
    }

    /** Recusada com R$ 0,00: uma nova tentativa com R$ 0,01 por unidade. */
    public function tentarComValorSimbolico(Order $bonus): bool
    {
        $bonus->loadMissing('items');
        if ((float) $bonus->items->sum('product_price') > 0) {
            return false;
        }

        DB::transaction(function () use ($bonus) {
            foreach ($bonus->items as $item) {
                $item->update([
                    'product_price' => self::VALOR_SIMBOLICO,
                    'subtotal' => round(self::VALOR_SIMBOLICO * $item->quantity, 2),
                ]);
            }
            $total = round($bonus->items->sum(fn ($i) => self::VALOR_SIMBOLICO * $i->quantity), 2);
            $bonus->update(['subtotal' => $total, 'total' => $total]);
        });

        Log::warning('nfe.bonificacao_valor_simbolico', ['order_id' => $bonus->id]);
        GenerateInvoiceJob::dispatch($bonus->id)->afterCommit();

        return true;
    }

    /** E-mail ao cliente com a nota de venda e a do brinde (uma vez só). */
    public function enviarEmail(Order $bonus, bool $deNovo = false): bool
    {
        $venda = $this->vendaDa($bonus);
        $email = $venda?->shipping_email ?: $venda?->user?->email;

        if (! $venda || ! $email || (! $deNovo && $bonus->bonus_email_enviado_em)) {
            return false;
        }

        Mail::to($email)->send(new NotaDoBrinde($venda, $bonus));
        $bonus->forceFill(['bonus_email_enviado_em' => now()])->save();

        return true;
    }
}
