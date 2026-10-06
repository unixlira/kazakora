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
 * As médias são recalculadas do extrato dos últimos 90 dias a cada uso:
 * se o TikTok mudar a cobrança, a estimativa acompanha conforme novos
 * extratos são importados. Quando o pedido aparece no extrato, a taxa
 * real substitui esta (ver orders:estimar-taxas-tiktok).
 */
class TikTokFeeEstimator
{
    /** Médias do extrato de 21/07 a 20/09/2026 — usadas só sem extrato recente. */
    private const PADRAO = ['afiliado' => 0.0445, 'comissao' => 0.0015, 'frete_por_pedido' => 7.17, 'pedidos' => 0];

    private const MINIMO_DE_PEDIDOS = 20;

    /** @var array{afiliado: float, comissao: float, frete_por_pedido: float, pedidos: int}|null */
    private ?array $medias = null;

    /**
     * @return array{fee_amount: float, commission_fee: float, service_fee: float, shipping_fee: float, seller_discount: null, platform_discount: null, payout_amount: float, breakdown: array<string, mixed>}
     */
    public function estimate(Order $order): array
    {
        $order->loadMissing('items');
        $medias = $this->medias();
        $venda = round((float) $order->subtotal, 2);

        $servico = 0.0;

        foreach ($order->items as $item) {
            $quantidade = max(1, (int) $item->quantity);
            $unitario = (float) $item->subtotal / $quantidade;
            $servico += ($unitario >= 50 ? 6 : 4) * $quantidade + 0.06 * (float) $item->subtotal;
        }

        $servico = round($servico, 2);
        $comissao = round($venda * $medias['comissao'], 2);
        $afiliado = round($venda * $medias['afiliado'], 2);
        $frete = round($medias['frete_por_pedido'], 2);
        $taxa = round($servico + $comissao + $afiliado + $frete, 2);

        return [
            'fee_amount' => $taxa,
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
                'base_pedidos_extrato' => $medias['pedidos'],
            ],
        ];
    }

    /**
     * @return array{afiliado: float, comissao: float, frete_por_pedido: float, pedidos: int}
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

        $recente = $base()->where('order_created_at', '>=', now()->subDays(90)->toDateString());
        $consulta = $recente->count() >= self::MINIMO_DE_PEDIDOS ? $recente : $base();

        $linha = $consulta->selectRaw('COUNT(*) as pedidos, SUM(product_net_sales) as venda,
            SUM(affiliate_commissions) as afiliado, SUM(platform_commission_fee) as comissao, SUM(net_shipping_cost) as frete')
            ->first();

        if (! $linha || (int) $linha->pedidos < self::MINIMO_DE_PEDIDOS || (float) $linha->venda <= 0) {
            return $this->medias = self::PADRAO;
        }

        // No extrato as taxas vêm negativas (saída de dinheiro); saldo
        // positivo (canal cobriu mais do que cobrou) não vira custo.
        return $this->medias = [
            'afiliado' => max(0.0, -(float) $linha->afiliado) / (float) $linha->venda,
            'comissao' => max(0.0, -(float) $linha->comissao) / (float) $linha->venda,
            'frete_por_pedido' => max(0.0, -(float) $linha->frete) / (int) $linha->pedidos,
            'pedidos' => (int) $linha->pedidos,
        ];
    }
}
