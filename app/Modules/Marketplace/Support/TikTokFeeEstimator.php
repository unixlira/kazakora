<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taxa ESTIMADA do TikTok Shop enquanto o extrato real não chega — o Bling
 * não traz a comissão do TikTok (taxaComissao sempre 0) e a API direta do
 * TikTok depende de aprovação no Partner Center. Pedido do usuário
 * 2026-10-06: "estimativa de lucro, taxas pagas ao TikTok".
 *
 * Tudo calibrado no extrato real (marketplace_settlement_details), não
 * em percentual chutado:
 *
 * - Taxa de serviço: R$ 4 por item (R$ 6 quando o item custa R$ 50 ou
 *   mais) + 6% da venda. Bateu em 145 de 146 pedidos do extrato de
 *   21/07 a 20/09.
 * - Afiliado e comissão da plataforma: não dá pra saber pelo pedido se a
 *   venda veio de afiliado, então entra o valor ESPERADO — a fração da
 *   venda que eles levaram nos pedidos liquidados (4,4% no extrato acima).
 * - Frete líquido cobrado da loja: média real por pedido (R$ 7,17).
 *
 * - Base (2026-11-02): o que o cliente pagou (total − frete), a mesma receita
 *   da conta oficial enquanto o extrato não chega. O subtotal é o preço cheio,
 *   antes do desconto da loja, e inflava a taxa (outubro: 55% × 46% real).
 * - Ajuste: o que o extrato cobrou além dessas parcelas, em % do pago, nos
 *   pedidos liquidados dos últimos 60 dias.
 *
 * As médias são recalculadas do extrato dos últimos 90 dias a cada uso:
 * se o TikTok mudar a cobrança, a estimativa acompanha conforme novos
 * extratos são importados. Quando o pedido aparece no extrato, a taxa
 * real substitui esta (ver orders:estimar-taxas-tiktok).
 */
class TikTokFeeEstimator
{
    /** Médias do extrato de 21/07 a 20/09/2026 — usadas só sem extrato recente. */
    private const PADRAO = ['afiliado' => 0.0445, 'comissao' => 0.0015, 'frete_por_pedido' => 7.17, 'ajuste' => 0.0, 'pedidos' => 0];

    private const MINIMO_DE_PEDIDOS = 20;

    private const DIAS_DE_BASE = 90;

    /** Agosto/2026 cobrou menos que hoje e, nos 90 dias, puxava o ajuste para baixo. */
    private const DIAS_DO_AJUSTE = 60;

    /** @var array{afiliado: float, comissao: float, frete_por_pedido: float, ajuste: float, pedidos: int}|null */
    private ?array $medias = null;

    /**
     * @return array{fee_amount: float, gross_amount: float, commission_fee: float, service_fee: float, shipping_fee: float, seller_discount: null, platform_discount: null, payout_amount: float, breakdown: array<string, mixed>}
     */
    public function estimate(Order $order): array
    {
        $medias = $this->medias();
        $partes = $this->parcelas($order, $medias);
        $venda = $partes['venda'];
        $servico = $partes['servico'];
        $comissao = $partes['comissao'];
        $afiliado = $partes['afiliado'];
        $frete = $partes['frete'];
        $ajuste = round($venda * $medias['ajuste'], 2);
        $taxa = round($servico + $comissao + $afiliado + $frete + $ajuste, 2);

        return [
            'fee_amount' => $taxa,
            'gross_amount' => $venda,
            'commission_fee' => $comissao,
            'service_fee' => $servico,
            'shipping_fee' => $frete,
            'seller_discount' => null,
            'platform_discount' => null,
            'payout_amount' => round($venda - $taxa, 2),
            'breakdown' => [
                'estimado' => true,
                'afiliado_esperado' => $afiliado,
                'taxa_afiliado' => round($medias['afiliado'], 4),
                'frete_medio' => $frete,
                'ajuste_extrato' => $ajuste,
                'base_pedidos_extrato' => $medias['pedidos'],
            ],
        ];
    }

    /**
     * @return array{venda: float, servico: float, comissao: float, afiliado: float, frete: float}
     */
    private function parcelas(Order $order, array $medias): array
    {
        $order->loadMissing('items');
        $subtotal = (float) $order->subtotal;
        $venda = round(max(0.0, (float) $order->total - (float) $order->shipping_cost), 2);
        $fator = $subtotal > 0 ? min(1.0, $venda / $subtotal) : 1.0;

        $servico = 0.0;

        foreach ($order->items as $item) {
            $quantidade = max(1, (int) $item->quantity);
            $pagoNoItem = (float) $item->subtotal * $fator;
            $servico += ($pagoNoItem / $quantidade >= 50 ? 6 : 4) * $quantidade + 0.06 * $pagoNoItem;
        }

        return [
            'venda' => $venda,
            'servico' => round($servico, 2),
            'comissao' => round($venda * $medias['comissao'], 2),
            'afiliado' => round($venda * $medias['afiliado'], 2),
            'frete' => round($medias['frete_por_pedido'], 2),
        ];
    }

    /**
     * Fração do pago que o extrato cobrou além das parcelas estimadas.
     *
     * @param  array{afiliado: float, comissao: float, frete_por_pedido: float}  $medias
     */
    private function ajuste(array $medias): float
    {
        $reais = DB::table('marketplace_settlement_details')
            ->where('channel', Order::ORIGIN_TIKTOK_SHOP)
            ->where('transaction_type', 'Pedido')
            ->where('order_created_at', '>=', now()->subDays(self::DIAS_DO_AJUSTE)->toDateString())
            ->groupBy('external_order_id')
            ->selectRaw('external_order_id, SUM(product_net_sales) - SUM(payout_amount) as taxa')
            ->pluck('taxa', 'external_order_id');

        $pedidos = Order::query()->where('origin', Order::ORIGIN_TIKTOK_SHOP)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->whereIn('external_order_id', $reais->keys())
            ->with('items')
            ->get();

        $pago = 0.0;
        $diferenca = 0.0;
        foreach ($pedidos as $pedido) {
            $partes = $this->parcelas($pedido, $medias);
            $pago += $partes['venda'];
            $diferenca += (float) $reais[$pedido->external_order_id] - $partes['servico'] - $partes['comissao'] - $partes['afiliado'] - $partes['frete'];
        }

        return $pedidos->count() >= self::MINIMO_DE_PEDIDOS && $pago > 0 ? max(0.0, $diferenca / $pago) : 0.0;
    }

    /**
     * @return array{afiliado: float, comissao: float, frete_por_pedido: float, ajuste: float, pedidos: int}
     */
    public function medias(): array
    {
        if ($this->medias !== null) {
            return $this->medias;
        }

        if (! Schema::hasTable('marketplace_settlement_details')) {
            return $this->medias = self::PADRAO;
        }

        $base = fn () => DB::table('marketplace_settlement_details')
            ->where('channel', Order::ORIGIN_TIKTOK_SHOP)
            ->where('transaction_type', 'Pedido')
            ->where('product_net_sales', '>', 0);

        $recente = $base()->where('order_created_at', '>=', now()->subDays(self::DIAS_DE_BASE)->toDateString());
        $consulta = $recente->count() >= self::MINIMO_DE_PEDIDOS ? $recente : $base();

        $linha = $consulta->selectRaw('COUNT(*) as pedidos, SUM(product_net_sales) as venda,
            SUM(affiliate_commissions) as afiliado, SUM(platform_commission_fee) as comissao, SUM(net_shipping_cost) as frete')
            ->first();

        if (! $linha || (int) $linha->pedidos < self::MINIMO_DE_PEDIDOS || (float) $linha->venda <= 0) {
            return $this->medias = self::PADRAO;
        }

        // No extrato as taxas vêm negativas (saída de dinheiro); saldo
        // positivo (canal cobriu mais do que cobrou) não vira custo.
        $medias = [
            'afiliado' => max(0.0, -(float) $linha->afiliado) / (float) $linha->venda,
            'comissao' => max(0.0, -(float) $linha->comissao) / (float) $linha->venda,
            'frete_por_pedido' => max(0.0, -(float) $linha->frete) / (int) $linha->pedidos,
            'pedidos' => (int) $linha->pedidos,
        ];

        return $this->medias = $medias + ['ajuste' => $this->ajuste($medias)];
    }
}
