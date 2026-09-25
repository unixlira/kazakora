<?php

namespace App\Modules\Marketplace\Drivers\Concerns;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Exceptions\ChannelOrderNotFoundException;
use App\Services\Bling\BlingOrderService;
use App\Services\Bling\Exceptions\BlingException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Canal cujo pedido chega pelo BLING, não pela API do próprio canal: o
 * vendedor conecta a loja uma vez dentro do painel do Bling, e o driver
 * consulta os pedidos já sincronizados lá, filtrados pela loja do Bling
 * que corresponde ao canal (ver BlingOrderService::lojaIdForChannel()).
 *
 * Nasceu no TikTokShopDriver (2026-08-31) e foi extraído quando a Amazon
 * passou a entrar pelo mesmo cano (2026-09-25) — os achados reais
 * documentados abaixo valem pros dois, porque o formato do pedido é o do
 * Bling, não o do canal.
 *
 * @property-read BlingOrderService $blingOrders
 */
trait ReadsOrdersFromBling
{
    /** Nome usado quando o Bling não traz o nome do comprador. */
    abstract protected function blingBuyerFallbackName(): string;

    /**
     * Gancho pro nome do item (o Bling só expõe o nome DENTRO do pedido,
     * não por código sozinho). Default: não guarda — só o TikTok precisa
     * dele pra casar produto por similaridade de nome.
     */
    protected function rememberBlingItemName(string $externalId, string $name): void {}

    /** Método de envio quando o volume no Bling ainda não informa o serviço. */
    abstract protected function blingShippingMethodFallback(): string;

    private function blingLojaId(): ?int
    {
        return $this->blingOrders->lojaIdForChannel($this->channel());
    }

    /**
     * Id universal de fábrica do Bling pra situação "Cancelado" — o mesmo
     * em toda conta Bling, não é customizável por conta feito as outras
     * situações. Ver mapBlingOrderStatus() pro porquê disso importar (escopo
     * OAuth insuficiente pra resolver o nome de qualquer situação por
     * API).
     */
    private const BLING_SITUACAO_CANCELADO_ID = 12;

    /**
     * $externalOrderId aqui é o número do pedido NO CANAL (o que o Bling
     * chama de `numeroLoja` — ex. "701-1234567-1234567" na Amazon), não um
     * id interno do Bling nem do canal — é esse número que fica salvo em
     * orders.external_order_id, pra bater com o que aparece de verdade pro
     * vendedor no painel do canal.
     */
    protected function importOrderFromBling(string $externalOrderId): array
    {
        $order = $this->blingOrders->findByOrderNumber($externalOrderId, $this->blingLojaId());

        if (! $order) {
            throw new RuntimeException("Pedido {$externalOrderId} não encontrado na loja do canal {$this->channel()} conectada ao Bling.");
        }

        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($order['itens'] ?? [] as $item) {
            $quantity = (int) ($item['quantidade'] ?? 0);
            $unitPrice = (float) ($item['valor'] ?? 0);
            // Prioriza o código/SKU real do produto — é ele que casa direto
            // com Product::sku (ver autoImportProduct() do driver). Cai pro id
            // interno do Bling só se o item não tiver código cadastrado lá.
            $externalId = $item['codigo'] ?? (isset($item['produto']['id']) ? 'BLING-'.$item['produto']['id'] : null);

            if ($externalId === null || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;

            // autoImportProduct() (chamado depois, separado, só com o
            // external_id) não recebe o nome do item — o Bling só expõe
            // isso DENTRO do pedido, não por código sozinho (ver docblock
            // completo lá). Guarda aqui pra ele conseguir consultar.
            if (! empty($item['descricao'])) {
                $this->rememberBlingItemName((string) $externalId, (string) $item['descricao']);
            }

            $items[] = [
                'external_id' => (string) $externalId,
                'external_name' => $item['descricao'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $buyerName = $order['contato']['nome'] ?? $this->blingBuyerFallbackName();
        $buyerDocument = isset($order['contato']['numeroDocumento'])
            ? preg_replace('/\D/', '', (string) $order['contato']['numeroDocumento'])
            : null;

        // Telefone/e-mail não vêm no pedido em si (só id/nome/documento do
        // contato) — precisa da chamada complementar, mesmo padrão já
        // usado pra CPF/CNPJ do Mercado Livre/Shopee. Nunca derruba a
        // importação se falhar (mesma cautela dos outros drivers): sem
        // telefone/e-mail o pedido ainda é importável, só com esses campos
        // vazios.
        $contact = null;

        if (isset($order['contato']['id'])) {
            try {
                $contact = $this->blingOrders->findContact((int) $order['contato']['id']);
            } catch (BlingException) {
                $contact = null;
            }
        }

        $address = $order['transporte']['etiqueta'] ?? [];
        $trackingCode = $order['transporte']['volumes'][0]['codigoRastreamento'] ?? null;

        return [
            'external_order_id' => (string) ($order['numeroLoja'] ?? $externalOrderId),
            'status' => $this->mapBlingOrderStatus($order),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round((float) ($order['transporte']['frete'] ?? 0), 2),
            'total' => round((float) ($order['total'] ?? $itemsSubtotal), 2),
            // BUG REAL 2026-08-31 (achado ao vivo — 3 notas rejeitadas pela
            // SEFAZ, "Total da NF difere do somatório dos valores"): quando o
            // TikTok Shop dá cupom/desconto, `total` do Bling já vem COM o
            // desconto aplicado, mas `itens[].valor` continua o preço CHEIO
            // — sem informar discount_amount, o XML monta vProd (soma dos
            // itens, cheio) e vNF (=order.total, já descontado) sem nenhum
            // vDesc pra explicar a diferença, e a SEFAZ recusa a conta.
            // `desconto.valor` do Bling é exatamente esse valor.
            'discount_amount' => round((float) ($order['desconto']['valor'] ?? 0), 2),
            'buyer_name' => $buyerName,
            'buyer_document' => $buyerDocument,
            'buyer_phone' => $contact['celular'] ?? $contact['telefone'] ?? null,
            'buyer_email' => $contact['email'] ?? null,
            'buyer_whatsapp' => null,
            'shipping_zip' => $address['cep'] ?? '00000000',
            'shipping_street' => $address['endereco'] ?? 'Não informado',
            'shipping_number' => $address['numero'] ?? 'S/N',
            'shipping_complement' => $address['complemento'] ?? null,
            'shipping_neighborhood' => $address['bairro'] ?? 'Não informado',
            'shipping_city' => $address['municipio'] ?? 'Não informado',
            'shipping_state' => $address['uf'] ?? 'SP',
            'external_shipment_id' => $trackingCode,
            // Bling só dá a DATA (sem hora) do pedido — mesmo assim melhor
            // que now() pra backfill (ver o mesmo argumento em
            // MercadoLivreDriver::importOrder()/ShopeeDriver::importOrder()
            // sobre created_at errado inflar métricas do dia errado).
            // Comissão da plataforma que o próprio canal cobrou — o Bling
            // traz em `taxas.taxaComissao` (valor em R$, schema oficial
            // VendasTaxaDTO). Só entra quando veio de verdade (> 0): sem ela
            // a taxa fica DESCONHECIDA no Fluxo de Caixa, nunca zero.
            ...((float) ($order['taxas']['taxaComissao'] ?? 0) > 0
                ? ['marketplace_fee' => round((float) $order['taxas']['taxaComissao'], 2)]
                : []),
            'placed_at' => isset($order['data']) ? \Illuminate\Support\Carbon::parse($order['data'], config('app.timezone')) : null,
            'items' => $items,
        ];
    }

    /**
     * BUG REAL 2026-08-31/09-01 (achado ao vivo — pedidos #1120 e #1135
     * ficaram travados pra sempre como "pago", nunca saiu etiqueta, um já
     * tinha sido embalado à toa): o token OAuth do Bling não tem o escopo
     * pra chamar `situacoes/{id}` — TODA chamada de situacaoName() sempre
     * devolvia 403 "insufficient_scope", o catch abaixo engolia o erro e
     * caía no default PAID sempre, silenciosamente. Ou seja, nenhum
     * cancelamento do TikTok JAMAIS foi detectado desde que essa
     * integração existe.
     *
     * Fix: `12` é o id universal do Bling pra "Cancelado" (situação padrão
     * de fábrica em toda conta Bling, não depende de escopo nenhum pra
     * checar — é só comparar o id numérico já presente na resposta de
     * pedidos/vendas). Checa isso PRIMEIRO, sem chamada de API nenhuma.
     * Só tenta resolver o nome via API (pra pegar situação CUSTOM de
     * cancelamento que a conta possa ter criado) como bônus best-effort;
     * se falhar (mesmo erro de escopo), mantém o default seguro PAID em
     * vez de travar o pedido.
     */
    protected function mapBlingOrderStatus(array $order): string
    {
        $situacaoId = $order['situacao']['id'] ?? null;

        if ($situacaoId === null) {
            return Order::STATUS_PAID;
        }

        if ((int) $situacaoId === self::BLING_SITUACAO_CANCELADO_ID) {
            return Order::STATUS_CANCELLED;
        }

        // Situações que significam "já despachado" NESTA conta do Bling.
        //
        // Vazio por padrão de propósito. Diferente do id 12 (Cancelado, de
        // fábrica em toda conta), as situações do fluxo de venda aqui são
        // CUSTOM da conta — em 2026-09-05 os ids reais eram 894763, 894764
        // e 894765 — e `situacoes/{id}` continua devolvendo 403 por falta
        // de escopo no token, então não há como descobrir o nome pela API.
        // Mapear por adivinhação daria BAIXA DE SEPARAÇÃO em pedido não
        // separado: o item nunca seria pego e o cliente não receberia.
        // Preencher BLING_SITUACOES_ENVIADO só depois de conferir no painel
        // do Bling qual id é o de enviado/concluído.
        if (in_array((int) $situacaoId, config('services.bling.situacoes_enviado', []), true)) {
            return Order::STATUS_SHIPPED;
        }

        try {
            $nome = mb_strtolower($this->blingOrders->situacaoName((int) $situacaoId) ?? '');
        } catch (BlingException) {
            return Order::STATUS_PAID;
        }

        return str_contains($nome, 'cancel') ? Order::STATUS_CANCELLED : Order::STATUS_PAID;
    }

    /**
     * Ao contrário do Mercado Livre/Shopee, aqui não CONFIRMAMOS nada —
     * o pickup/entrega é 100% da logística própria do TikTok Shop (o
     * pedido real já vem com `transporte.volumes[0].servico` tipo
     * "LSV-Standard-BR PICKUP", achado ao vivo 2026-08-31), Bling só
     * reflete o que o TikTok já decidiu. Isto é pura CONSULTA (mesmo
     * espírito do comentário original sobre Flex x padrão do Mercado
     * Livre), útil só pra pegar o código de rastreio assim que o TikTok
     * atribuir um.
     */
    protected function confirmShippingFromBling(Order $order): array
    {
        // BUG REAL 2026-08-31 (achado ao vivo reprocessando o backlog):
        // pedido tiktok_shop sem external_order_id (ex: criado manualmente
        // sem preencher esse campo) estourava TypeError cru aqui em vez de
        // um erro claro — findByOrderNumber() exige string.
        if (! $order->external_order_id) {
            // Permanente: sem o número do pedido no canal não há nem o que
            // perguntar — nenhuma tentativa futura inventa esse dado.
            throw new ChannelOrderNotFoundException("Pedido #{$order->id} não tem external_order_id — não dá pra consultar o envio no Bling.");
        }

        $blingOrder = $this->blingOrders->findByOrderNumber($order->external_order_id, $this->blingLojaId());

        if (! $blingOrder) {
            // Permanente: o Bling não conhece esse pedido, e não vai passar
            // a conhecer sozinho. Ver ChannelOrderNotFoundException — foi
            // este erro, retentado por um mês, que entupiu a fila.
            throw new ChannelOrderNotFoundException("Pedido {$order->external_order_id} não encontrado no Bling ao consultar o envio.");
        }

        $volume = $blingOrder['transporte']['volumes'][0] ?? [];
        $trackingCode = $volume['codigoRastreamento'] ?: null;

        return [
            'external_shipment_id' => (string) $blingOrder['id'],
            'tracking_code' => $trackingCode,
            'shipping_method' => $volume['servico'] ?? $this->blingShippingMethodFallback(),
            'status' => $trackingCode ? 'confirmed' : 'pending',
        ];
    }

    /**
     * `logisticas/etiquetas` real do Bling (ver BlingOrderService::
     * fetchLabel() pro docblock completo, incluindo o aviso de que isto
     * NÃO foi testado contra um PDF de verdade ainda — nenhum pedido da
     * conta do usuário tinha logística cadastrada no momento em que foi
     * escrito). `ready: false` tanto pra "pedido ainda sem rastreio" (não
     * é erro, CheckShipmentLabelJob tenta de novo) quanto pra falha real
     * no download do PDF em si — mesma cautela de nunca derrubar o
     * pipeline por uma etiqueta que só ainda não chegou.
     */
    protected function fetchLabelFromBling(Order $order): array
    {
        $blingOrder = $this->blingOrders->findByOrderNumber($order->external_order_id, $this->blingLojaId());

        if (! $blingOrder) {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $label = $this->blingOrders->fetchLabel((int) $blingOrder['id']);

        if (! $label) {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $response = Http::timeout(20)->get($label['link']);

        if ($response->failed()) {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        return [
            'ready' => true,
            'contents' => $response->body(),
            'content_type' => $response->header('Content-Type') ?: 'application/pdf',
        ];
    }
}
