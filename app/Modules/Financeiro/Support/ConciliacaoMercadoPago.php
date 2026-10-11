<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Financeiro\Models\MercadoPagoPagamento;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Conciliação da conta do Mercado Pago (pedido 2026-10-11: "a mesma API do
 * Mercado Livre é a que retorna dados do Mercado Pago do KazaKora... pelo
 * número da transação do KazaKora você sabe diferenciar o que é do site do
 * que é do Mercado Livre").
 *
 * 1. baixar(): todos os pagamentos da conta no período (/v1/payments/search).
 *    Site = número da transação guardado em payments.mercadopago_payment_id;
 *    ML = order.type "mercadolibre" (order.id = pedido do ML); bônus do
 *    Flex = transferência "bonificaciones_flex".
 * 2. aplicar(): pra cada pedido, o que entrou de verdade (net_received_amount
 *    menos o que foi devolvido) vira a taxa real (source report): taxa =
 *    receita do pedido − líquido. Em setembro/2026 o ML ficava com ~29% da
 *    venda (comissão + frete cobrado do vendedor), e o sistema só via ~16%.
 *    Pedido com todos os pagamentos estornados sai da receita (estornado_em).
 */
class ConciliacaoMercadoPago
{
    private const API = 'https://api.mercadopago.com/v1/payments/search';

    /** @return array{baixados: int, por_canal: array<string, int>} */
    public function baixar(Carbon $desde, Carbon $ate): array
    {
        $token = config('services.mercadopago.access_token');
        if (! $token) {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN não configurado.');
        }

        $transacoesDoSite = Payment::query()->whereNotNull('mercadopago_payment_id')->pluck('order_id', 'mercadopago_payment_id');
        $baixados = 0;
        $porCanal = [];

        // Janelas de 7 dias: a busca do MP pagina por offset e fica lenta/instável em offsets altos.
        for ($inicio = $desde->copy(); $inicio->lt($ate); $inicio->addDays(7)) {
            $fim = $inicio->copy()->addDays(7)->min($ate);
            $offset = 0;

            do {
                $resposta = Http::withToken($token)->timeout(60)->retry(3, 2000)->get(self::API, [
                    'sort' => 'date_created',
                    'criteria' => 'asc',
                    'range' => 'date_created',
                    'begin_date' => $inicio->copy()->format('Y-m-d\TH:i:s.vP'),
                    'end_date' => $fim->copy()->format('Y-m-d\TH:i:s.vP'),
                    'limit' => 100,
                    'offset' => $offset,
                ]);

                if ($resposta->failed()) {
                    throw new RuntimeException('Mercado Pago recusou a busca de pagamentos: HTTP '.$resposta->status());
                }

                $resultados = $resposta->json('results') ?? [];
                foreach ($resultados as $pagamento) {
                    $canal = $this->guardar($pagamento, $transacoesDoSite);
                    $porCanal[$canal] = ($porCanal[$canal] ?? 0) + 1;
                    $baixados++;
                }

                $offset += 100;
                $total = (int) $resposta->json('paging.total', 0);
            } while ($resultados && $offset < $total);
        }

        return ['baixados' => $baixados, 'por_canal' => $porCanal];
    }

    /** @param Collection<string, int> $transacoesDoSite */
    private function guardar(array $p, Collection $transacoesDoSite): string
    {
        $id = (string) $p['id'];
        $ordem = $p['order'] ?? [];
        $descricao = (string) ($p['description'] ?? '');
        $orderId = null;
        $mlOrderId = null;

        if ($transacoesDoSite->has($id)) {
            $canal = MercadoPagoPagamento::CANAL_LOJA;
            $orderId = $transacoesDoSite->get($id);
        } elseif (($ordem['type'] ?? null) === 'mercadolibre' && ! empty($ordem['id'])) {
            $canal = MercadoPagoPagamento::CANAL_MERCADO_LIVRE;
            $mlOrderId = (string) $ordem['id'];
            $orderId = Order::query()
                ->where('origin', Order::ORIGIN_MERCADO_LIVRE)
                ->where(fn ($q) => $q->where('external_order_id', $mlOrderId)->orWhere('channel_pack_id', $mlOrderId))
                ->orderBy('id')
                ->value('id');
        } elseif (($p['operation_type'] ?? null) === 'money_transfer' && str_starts_with($descricao, 'bonificaciones')) {
            $canal = MercadoPagoPagamento::CANAL_BONIFICACAO;
        } else {
            $canal = MercadoPagoPagamento::CANAL_OUTRO;
        }

        $data = fn ($valor) => $valor ? Carbon::parse($valor)->setTimezone(config('app.timezone')) : null;

        MercadoPagoPagamento::query()->updateOrCreate(['mp_payment_id' => $id], [
            'data_criacao' => $data($p['date_created'] ?? null),
            'data_aprovacao' => $data($p['date_approved'] ?? null),
            'data_liberacao' => $data($p['money_release_date'] ?? null),
            'status' => (string) ($p['status'] ?? ''),
            'status_detalhe' => isset($p['status_detail']) ? mb_substr((string) $p['status_detail'], 0, 60) : null,
            'tipo_operacao' => $p['operation_type'] ?? null,
            'canal' => $canal,
            'ml_order_id' => $mlOrderId,
            'referencia_externa' => isset($p['external_reference']) ? mb_substr((string) $p['external_reference'], 0, 120) : null,
            'descricao' => mb_substr($descricao, 0, 190) ?: null,
            'meio_pagamento' => $p['payment_method_id'] ?? null,
            'valor' => round((float) ($p['transaction_amount'] ?? 0), 2),
            'liquido' => round((float) ($p['transaction_details']['net_received_amount'] ?? 0), 2),
            'reembolsado' => round((float) ($p['transaction_amount_refunded'] ?? 0), 2),
            'order_id' => $orderId,
        ]);

        return $canal;
    }

    /**
     * Grava a taxa real e os estornos dos pedidos com pagamento no período.
     *
     * @return array{taxas: int, estornos: int, desfeitos: int, manuais: int, taxa_antes: float, taxa_depois: float, divergencias: list<string>}
     */
    public function aplicar(Carbon $desde, Carbon $ate, bool $seco = false): array
    {
        $resumo = ['taxas' => 0, 'estornos' => 0, 'desfeitos' => 0, 'manuais' => 0, 'taxa_antes' => 0.0, 'taxa_depois' => 0.0, 'divergencias' => []];

        $pagamentos = MercadoPagoPagamento::query()
            ->whereIn('canal', [MercadoPagoPagamento::CANAL_LOJA, MercadoPagoPagamento::CANAL_MERCADO_LIVRE])
            ->whereNotNull('order_id')
            ->whereIn('order_id', MercadoPagoPagamento::query()->select('order_id')->whereNotNull('order_id')
                ->where('data_criacao', '>=', $desde)->where('data_criacao', '<', $ate))
            ->get()
            ->groupBy('order_id');

        $pedidos = Order::query()->with('channelFee')->whereIn('id', $pagamentos->keys())->get()->keyBy('id');

        foreach ($pagamentos as $orderId => $doPedido) {
            $pedido = $pedidos->get($orderId);
            if (! $pedido) {
                continue;
            }

            // Pagamento de um PACOTE do ML (carrinho com vários pedidos): o
            // order.id do MP é o pack_id. Divide pela receita de cada pedido.
            $mlId = $doPedido->first()->ml_order_id;
            $pacote = $mlId && $pedido->external_order_id !== $mlId
                ? Order::query()->with('channelFee')->where('origin', Order::ORIGIN_MERCADO_LIVRE)->where('channel_pack_id', $mlId)->orderBy('id')->get()
                : collect([$pedido]);
            if ($pacote->count() > 1) {
                $this->aplicarPacote($pacote, $doPedido, $seco, $resumo);

                continue;
            }

            $validos = $doPedido->filter(fn ($p) => in_array($p->status, MercadoPagoPagamento::STATUS_VALIDOS, true));
            $estornados = $doPedido->filter(fn ($p) => in_array($p->status, MercadoPagoPagamento::STATUS_ESTORNO, true));

            // Venda devolvida por inteiro: nada ficou na conta.
            if ($validos->isEmpty()) {
                if ($estornados->isNotEmpty() && ! $pedido->estornado_em) {
                    $resumo['estornos']++;
                    if (in_array($pedido->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
                        $resumo['divergencias'][] = "#{$pedido->id} ({$pedido->origin} {$pedido->external_order_id}) está \"{$pedido->status}\" no sistema, mas foi estornado no Mercado Pago (R$ ".number_format((float) $estornados->sum('valor'), 2, ',', '.').')';
                    }
                    if (! $seco) {
                        $pedido->forceFill(['estornado_em' => now(), 'estorno_origem' => 'mercado_pago'])->save();
                    }
                }

                continue;
            }

            // Estorno marcado antes, mas o pagamento voltou a valer (mediação ganha).
            if ($pedido->estornado_em && $pedido->estorno_origem === 'mercado_pago') {
                $resumo['desfeitos']++;
                if (! $seco) {
                    $pedido->forceFill(['estornado_em' => null, 'estorno_origem' => null])->save();
                }
            }

            $liquido = round((float) $validos->sum(fn ($p) => (float) $p->liquido - (float) $p->reembolsado), 2);
            $bruto = round((float) $validos->sum('valor'), 2);
            // Site: a taxa é só a do MP (o cliente pagou frete/desconto à parte).
            // ML: tudo o que separa a receita (subtotal) do que entrou.
            $taxa = $pedido->origin === Order::ORIGIN_MERCADO_LIVRE
                ? round((float) $pedido->subtotal - $liquido, 2)
                : round($bruto - $liquido, 2);

            $atual = $pedido->channelFee;
            if ($atual?->source === OrderChannelFee::SOURCE_MANUAL) {
                $resumo['manuais']++;

                continue;
            }

            $resumo['taxa_antes'] += (float) ($atual?->fee_amount ?? 0);
            $resumo['taxa_depois'] += $taxa;
            $resumo['taxas']++;

            if ($seco) {
                continue;
            }

            OrderChannelFee::query()->updateOrCreate(['order_id' => $pedido->id], [
                'channel' => $pedido->origin,
                'gross_amount' => $pedido->subtotal,
                'fee_amount' => $taxa,
                'payout_amount' => $liquido,
                'breakdown' => [
                    'fonte' => 'mercado_pago',
                    'pagamentos' => $validos->pluck('mp_payment_id')->values()->all(),
                    'bruto_mp' => $bruto,
                    'liquido_mp' => $liquido,
                    'comissao_api_anterior' => $atual?->source === OrderChannelFee::SOURCE_API ? (float) $atual->fee_amount : null,
                ],
                'source' => OrderChannelFee::SOURCE_REPORT,
                'computed_at' => now(),
            ]);
        }

        $resumo['taxa_antes'] = round($resumo['taxa_antes'], 2);
        $resumo['taxa_depois'] = round($resumo['taxa_depois'], 2);

        return $resumo;
    }

    /** Pacote do ML: o líquido do(s) pagamento(s) dividido pela receita de cada pedido. */
    private function aplicarPacote(Collection $pacote, Collection $pagamentos, bool $seco, array &$resumo): void
    {
        $validos = $pagamentos->filter(fn ($p) => in_array($p->status, MercadoPagoPagamento::STATUS_VALIDOS, true));
        $receitaTotal = (float) $pacote->sum('subtotal');
        if ($validos->isEmpty() || $receitaTotal <= 0) {
            return;
        }
        $liquido = (float) $validos->sum(fn ($p) => (float) $p->liquido - (float) $p->reembolsado);

        foreach ($pacote as $pedido) {
            if ($pedido->channelFee?->source === OrderChannelFee::SOURCE_MANUAL) {
                $resumo['manuais']++;

                continue;
            }
            $parte = round($liquido * (float) $pedido->subtotal / $receitaTotal, 2);
            $taxa = round((float) $pedido->subtotal - $parte, 2);
            $resumo['taxa_antes'] += (float) ($pedido->channelFee?->fee_amount ?? 0);
            $resumo['taxa_depois'] += $taxa;
            $resumo['taxas']++;
            if ($seco) {
                continue;
            }
            OrderChannelFee::query()->updateOrCreate(['order_id' => $pedido->id], [
                'channel' => $pedido->origin,
                'gross_amount' => $pedido->subtotal,
                'fee_amount' => $taxa,
                'payout_amount' => $parte,
                'breakdown' => ['fonte' => 'mercado_pago', 'pacote' => $pedido->channel_pack_id, 'pagamentos' => $validos->pluck('mp_payment_id')->values()->all(), 'liquido_pacote' => round($liquido, 2)],
                'source' => OrderChannelFee::SOURCE_REPORT,
                'computed_at' => now(),
            ]);
        }
    }
}
