<?php

namespace App\Modules\App\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Services\CheckoutOutroSite;
use App\Modules\Checkout\Support\CancelamentoDoPedido;
use App\Modules\Fiscal\Models\Invoice;

/**
 * Objetos do contrato do app (kazakora-app/docs/api.md): usuario, Endereco,
 * PedidoResumo e Pedido. Dinheiro em centavos, datas ISO 8601 em São Paulo.
 */
class FormatoApp
{
    /** Situação do pedido no app => rótulo (o mesmo das etapas do site). */
    public const STATUS = [
        'aguardando_pagamento' => 'Aguardando pagamento',
        'pago' => 'Pagamento aprovado',
        'em_separacao' => 'Em preparação',
        'enviado' => 'Pedido enviado',
        'entregue' => 'Entregue',
        'cancelado' => 'Cancelado',
    ];

    public static function usuario(User $user): array
    {
        return [
            'id' => $user->id,
            'nome' => $user->name,
            'email' => $user->email,
            'cpf_mascarado' => self::cpfMascarado($user->cpf),
            'telefone' => $user->phone,
        ];
    }

    /** "***.456.789-**": o app mostra sem expor o CPF inteiro. */
    public static function cpfMascarado(?string $cpf): ?string
    {
        $numeros = preg_replace('/\D/', '', (string) $cpf);

        return strlen($numeros) === 11 ? '***.'.substr($numeros, 3, 3).'.'.substr($numeros, 6, 3).'-**' : null;
    }

    public static function endereco(Address $address): array
    {
        return [
            'id' => $address->id,
            'apelido' => $address->label,
            'destinatario' => $address->recipient_name,
            'telefone' => $address->phone,
            'cep' => preg_replace('/\D/', '', (string) $address->zip),
            'rua' => $address->street,
            'numero' => $address->number,
            'complemento' => $address->complement,
            'bairro' => $address->neighborhood,
            'cidade' => $address->city,
            'uf' => $address->state,
            'principal' => (bool) $address->is_default,
        ];
    }

    /**
     * Status do Order (pending, awaiting_payment, paid, shipped, completed,
     * cancelled) no vocabulário do app. "em_separacao" = pago e já separado
     * ou com etiqueta, a etapa "Em preparação" do site.
     */
    public static function status(Order $order): string
    {
        $etapas = collect($order->etapas())->keyBy('titulo');

        return match (true) {
            $order->status === Order::STATUS_CANCELLED => 'cancelado',
            $order->status === Order::STATUS_COMPLETED => 'entregue',
            $etapas['Pedido enviado']['feito'] ?? false => 'enviado',
            $etapas['Em preparação']['feito'] ?? false => 'em_separacao',
            $etapas['Pagamento aprovado']['feito'] ?? false => 'pago',
            default => 'aguardando_pagamento',
        };
    }

    public static function pago(Order $order): bool
    {
        return in_array($order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true);
    }

    /** Situação curta (GET /pedidos/{id}/status e polling do Pix). */
    public static function situacao(Order $order): array
    {
        $status = self::status($order);

        return ['status' => $status, 'status_texto' => self::STATUS[$status], 'pago' => self::pago($order)];
    }

    /**
     * PedidoResumo (lista) ou Pedido (detalhe, $detalhe = true: endereço,
     * valores e etapas). $pix = dados do QR enquanto aguarda o Pix.
     */
    public static function pedido(Order $order, bool $detalhe = false, ?array $pix = null): array
    {
        $order->loadMissing(['items.product.images', 'items.product.parent.images', 'payments', 'invoice', 'channelShipment', 'latestCorreiosPrePostagem']);
        $pagamento = $order->payments->sortByDesc('id')->first();
        $codigo = $order->trackingCode();

        $pedido = [
            'id' => $order->id,
            'numero' => $order->numero,
            'criado_em' => $order->created_at?->toIso8601String(),
            ...self::situacao($order),
            'total' => CheckoutOutroSite::centavos((float) $order->total),
            'metodo' => $pagamento?->method_type,
            'itens' => $order->items->map(fn ($item) => [
                'nome' => $item->product_name,
                'imagem' => self::fotoDoItem($item),
                'quantidade' => (int) $item->quantity,
                'preco_unitario' => CheckoutOutroSite::centavos((float) $item->product_price),
            ])->values()->all(),
            'rastreio' => $codigo || $order->shipping_carrier_name ? [
                'codigo' => $codigo,
                'transportadora' => $order->shipping_carrier_name,
                // Página de rastreio do site (com login), a mesma do WhatsApp e do e-mail.
                'link' => $codigo ? route('rastreio.ver', $order->trackingRef()) : null,
                // O site também não mostra os eventos dos Correios: vale "etapas".
                'eventos' => [],
            ] : null,
            'pix' => $pix,
        ];

        if (! $detalhe) {
            return $pedido;
        }

        return [
            ...$pedido,
            'etapas' => $order->etapas(),
            // Botão "Cancelar pedido" (mesma regra de Meus pedidos); null = pode.
            'cancelar_bloqueio' => $bloqueio = app(CancelamentoDoPedido::class)->bloqueioDoCliente($order),
            'pode_cancelar' => $bloqueio === null,
            'tem_nota' => $order->invoice?->status === Invoice::STATUS_AUTHORIZED && filled($order->invoice->danfe_path),
            'subtotal' => CheckoutOutroSite::centavos((float) $order->subtotal),
            'frete' => CheckoutOutroSite::centavos((float) $order->shipping_cost),
            // Desconto total do pedido (cupom + Pix + caixa única), como discount_amount.
            'desconto' => CheckoutOutroSite::centavos((float) $order->discount_amount),
            'desconto_pix' => CheckoutOutroSite::centavos((float) $order->pix_discount_amount),
            'endereco' => [
                'destinatario' => $order->shipping_name,
                'telefone' => $order->shipping_phone,
                'cep' => preg_replace('/\D/', '', (string) $order->shipping_zip),
                'rua' => $order->shipping_street,
                'numero' => $order->shipping_number,
                'complemento' => $order->shipping_complement,
                'bairro' => $order->shipping_neighborhood,
                'cidade' => $order->shipping_city,
                'uf' => $order->shipping_state,
            ],
            'boleto' => $pagamento?->method_type === Payment::METHOD_BOLETO && $pagamento->boletoPendente() ? [
                'linha_digitavel' => $pagamento->boleto_linha_digitavel,
                'url' => $pagamento->boleto_url,
                'vencimento' => $pagamento->expira_em?->toIso8601String(),
            ] : null,
        ];
    }

    private static function fotoDoItem($item): ?string
    {
        $imagens = $item->product?->images ?? collect();
        $foto = $imagens->firstWhere('is_primary', true) ?? $imagens->first()
            ?? $item->product?->parent?->images->first();

        return $foto?->thumb_url ?? $foto?->url;
    }
}
