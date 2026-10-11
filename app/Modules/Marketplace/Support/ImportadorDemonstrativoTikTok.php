<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lê o Excel de Finanças › Demonstrativos do TikTok ("Exibir por pedidos",
 * abas Detalhes do pedido / Extratos / Pagamentos) e grava cada linha em
 * marketplace_settlement_details — o valor REAL liquidado de cada pedido
 * (2026-10-11). Reimportar o mesmo período atualiza, não duplica.
 *
 * Conferência: a soma das linhas de cada demonstrativo tem que bater com o
 * "Valor total a ser liquidado" da aba Extratos.
 */
class ImportadorDemonstrativoTikTok
{
    /** Cabeçalho do TikTok (pt-BR) => coluna da tabela. */
    private const COLUNAS = [
        'Data do demonstrativo' => 'settlement_date',
        'ID do demonstrativo' => 'statement_id',
        'ID do pagamento' => 'payment_id',
        'Status' => 'status',
        'Moeda' => 'currency',
        'Tipo de transação' => 'transaction_type',
        'ID do pedido/ajuste' => 'external_order_id',
        'ID do SKU' => 'external_sku_id',
        'Quantidade' => 'quantity',
        'Nome do produto' => 'product_name',
        'Nome do SKU' => 'sku_name',
        'Data de criação do pedido' => 'order_created_at',
        'Data de entrega do pedido' => 'order_delivered_at',
        'Valor total a ser liquidado' => 'payout_amount',
        'Vendas líquidas dos produtos' => 'product_net_sales',
        'Subtotal do item antes dos descontos' => 'item_subtotal_before_discounts',
        'Descontos financiados pelo vendedor' => 'seller_discounts',
        'Desconto da plataforma' => 'platform_product_discounts',
        'Desconto de cupom cofinanciado pela plataforma' => 'platform_coupon_discounts',
        'Reembolso de desconto do cupom cofinanciado pela plataforma' => 'platform_coupon_discount_refunds',
        'Reembolsos de produtos' => 'product_refunds',
        'Custo líquido de frete' => 'net_shipping_cost',
        'Custo do frete' => 'shipping_cost',
        'Taxa de frete paga pelo cliente' => 'customer_shipping_fee',
        'Custo de frete coberto pelo TikTok Shop' => 'channel_shipping_coverage',
        'Desconto na taxa de envio do TikTok Shop para o cliente' => 'platform_shipping_discounts',
        'Custo de frete para devoluções' => 'return_shipping_cost',
        'Taxas e impostos' => 'platform_fees_taxes',
        'Tarifa de comissão da plataforma' => 'platform_commission_fee',
        'Taxas de serviço' => 'service_fees',
        'Taxa de serviço do SFP' => 'sfp_service_fee',
        'Impostos' => 'taxes',
        'ICMS DIFAL' => 'icms_difal',
        'Multa de ICMS' => 'icms_fine',
        'Comissões de afiliados' => 'affiliate_commissions',
        'Comissão de afiliados estimada' => 'estimated_affiliate_commissions',
        'Comissão paga às agências parceiras' => 'agency_partner_commission',
        'Comissão de Anúncios da loja paga aos criadores' => 'shop_ads_creator_commission',
        'Comissão de Anúncios da loja paga às agências parceiras' => 'shop_ads_agency_commission',
        'Taxa de anúncio de GMV Max' => 'gmv_max_ad_fee',
        'Valor do ajuste' => 'adjustment_amount',
        'Motivo do ajuste' => 'adjustment_reason',
        'ID do pedido relacionado' => 'related_order_id',
        'Pagamento do cliente' => 'customer_payment',
    ];

    private const TEXTO = ['statement_id', 'payment_id', 'status', 'currency', 'transaction_type', 'external_order_id', 'external_sku_id', 'product_name', 'sku_name', 'adjustment_reason', 'related_order_id'];

    private const DATAS = ['settlement_date', 'order_created_at', 'order_delivered_at'];

    /**
     * @return array{linhas: int, pedidos: int, demonstrativos: int, liquido: float, de: ?string, ate: ?string, divergencias: list<string>}
     */
    public function importar(string $caminhoAbsoluto, string $nomeArquivo): array
    {
        $abas = $this->lerXlsx($caminhoAbsoluto);
        $detalhes = $abas['Detalhes do pedido'] ?? throw new RuntimeException('Arquivo sem a aba "Detalhes do pedido" — exporte em "Exibir por pedidos".');

        $cabecalho = array_map('trim', array_shift($detalhes) ?? []);
        $faltando = array_diff(['ID do demonstrativo', 'ID do pedido/ajuste', 'Valor total a ser liquidado'], $cabecalho);
        if ($faltando) {
            throw new RuntimeException('Colunas esperadas não vieram: '.implode(', ', $faltando));
        }

        $agora = now();
        $linhas = [];
        foreach ($detalhes as $linha) {
            if (trim((string) ($linha[array_search('ID do demonstrativo', $cabecalho, true)] ?? '')) === '') {
                continue;
            }
            $registro = ['channel' => Order::ORIGIN_TIKTOK_SHOP, 'source_file' => mb_substr($nomeArquivo, 0, 255), 'created_at' => $agora, 'updated_at' => $agora];
            foreach ($cabecalho as $i => $titulo) {
                if (! isset(self::COLUNAS[$titulo])) {
                    continue;
                }
                $coluna = self::COLUNAS[$titulo];
                $valor = trim((string) ($linha[$i] ?? ''));
                $registro[$coluna] = match (true) {
                    in_array($coluna, self::DATAS, true) => $this->data($valor),
                    in_array($coluna, self::TEXTO, true) => in_array($valor, ['/', ''], true) ? ($coluna === 'external_sku_id' ? '' : null) : $valor,
                    $coluna === 'quantity' => (int) $valor,
                    default => is_numeric($valor) ? round((float) $valor, 2) : 0.0,
                };
            }
            $linhas[] = $registro;
        }

        if ($linhas === []) {
            throw new RuntimeException('A aba "Detalhes do pedido" veio vazia.');
        }

        $atualizar = array_values(array_diff(array_keys($linhas[0]), ['channel', 'statement_id', 'external_order_id', 'external_sku_id', 'transaction_type', 'created_at']));
        foreach (array_chunk($linhas, 200) as $lote) {
            DB::table('marketplace_settlement_details')->upsert($lote, ['channel', 'statement_id', 'external_order_id', 'external_sku_id', 'transaction_type'], $atualizar);
        }

        $taxas = $this->aplicarTaxasReais(array_values(array_unique(array_column($linhas, 'external_order_id'))));
        $datas = array_filter(array_column($linhas, 'settlement_date'));

        return [
            'linhas' => count($linhas),
            'pedidos' => count(array_unique(array_column(array_filter($linhas, fn ($l) => $l['transaction_type'] === 'Pedido'), 'external_order_id'))),
            'demonstrativos' => count(array_unique(array_column($linhas, 'statement_id'))),
            'liquido' => round(array_sum(array_column($linhas, 'payout_amount')), 2),
            'de' => $datas ? min($datas) : null,
            'ate' => $datas ? max($datas) : null,
            'divergencias' => $this->conferir($linhas, $abas['Extratos'] ?? []),
            'taxas_reais' => $taxas,
        ];
    }

    /**
     * Taxa REAL de cada pedido liquidado (vendas líquidas − valor liquidado:
     * comissão, SFP, taxa por item, afiliado e frete líquido) — substitui a
     * estimativa na margem. Sem ids, refaz para todo o extrato.
     *
     * @param  list<string>|null  $ids
     */
    public function aplicarTaxasReais(?array $ids = null): int
    {
        $porPedido = DB::table('marketplace_settlement_details')
            ->where('channel', Order::ORIGIN_TIKTOK_SHOP)
            ->where('transaction_type', 'Pedido')
            ->when($ids !== null, fn ($q) => $q->whereIn('external_order_id', $ids))
            ->groupBy('external_order_id')
            ->selectRaw('external_order_id, SUM(product_net_sales) venda, SUM(payout_amount) liquido, SUM(platform_commission_fee) comissao, SUM(service_fees) servico, SUM(net_shipping_cost) frete, SUM(seller_discounts) desconto')
            ->get();

        $pedidos = Order::query()->where('origin', Order::ORIGIN_TIKTOK_SHOP)
            ->whereIn('external_order_id', $porPedido->pluck('external_order_id'))
            ->pluck('id', 'external_order_id');

        $gravadas = 0;
        foreach ($porPedido as $l) {
            if (! isset($pedidos[$l->external_order_id])) {
                continue;
            }
            \App\Modules\Marketplace\Models\OrderChannelFee::query()->updateOrCreate(
                ['order_id' => $pedidos[$l->external_order_id], 'channel' => Order::ORIGIN_TIKTOK_SHOP],
                [
                    'gross_amount' => round((float) $l->venda, 2),
                    'fee_amount' => round((float) $l->venda - (float) $l->liquido, 2),
                    'commission_fee' => round(abs((float) $l->comissao), 2),
                    'service_fee' => round(abs((float) $l->servico), 2),
                    'shipping_fee' => round(-(float) $l->frete, 2),
                    'seller_discount' => round(abs((float) $l->desconto), 2),
                    'payout_amount' => round((float) $l->liquido, 2),
                    'source' => \App\Modules\Marketplace\Models\OrderChannelFee::SOURCE_REPORT,
                    'computed_at' => now(),
                ],
            );
            $gravadas++;
        }

        return $gravadas;
    }

    /** Conferência 1 do plano: soma das linhas = valor do demonstrativo. */
    private function conferir(array $linhas, array $extratos): array
    {
        $cab = array_map('trim', array_shift($extratos) ?? []);
        $iId = array_search('ID do demonstrativo', $cab, true);
        $iValor = array_search('Valor total a ser liquidado', $cab, true);
        if ($iId === false || $iValor === false) {
            return [];
        }

        $somas = [];
        foreach ($linhas as $l) {
            $somas[$l['statement_id']] = ($somas[$l['statement_id']] ?? 0) + $l['payout_amount'];
        }

        $divergencias = [];
        foreach ($extratos as $e) {
            $id = trim((string) ($e[$iId] ?? ''));
            if ($id === '' || ! isset($somas[$id])) {
                continue;
            }
            $esperado = round((float) ($e[$iValor] ?? 0), 2);
            if (abs($esperado - round($somas[$id], 2)) > 0.01) {
                $divergencias[] = "demonstrativo {$id}: linhas somam R$ ".number_format($somas[$id], 2, ',', '.').', extrato diz R$ '.number_format($esperado, 2, ',', '.');
            }
        }

        return $divergencias;
    }

    private function data(string $valor): ?string
    {
        if ($valor === '' || $valor === '/') {
            return null;
        }
        if (is_numeric($valor)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $valor)->toDateString();
        }

        return rescue(fn () => Carbon::createFromFormat('Y/m/d', substr($valor, 0, 10))->toDateString(), null, false);
    }

    /** @return array<string, list<list<string>>> nome da aba => linhas */
    public function lerXlsx(string $caminho): array
    {
        $zip = new ZipArchive();
        if ($zip->open($caminho) !== true) {
            throw new RuntimeException('Não é um arquivo Excel (.xlsx) válido.');
        }

        $compartilhadas = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach ((new SimpleXMLElement($xml))->si as $si) {
                $compartilhadas[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
            }
        }

        $livro = new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
        $rels = new SimpleXMLElement($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $alvos = [];
        foreach ($rels->Relationship as $r) {
            $alvos[(string) $r['Id']] = 'xl/'.ltrim(str_replace('/xl/', '', (string) $r['Target']), '/');
        }

        $abas = [];
        foreach ($livro->sheets->sheet as $folha) {
            $rid = (string) $folha->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $conteudo = $zip->getFromName($alvos[$rid] ?? '');
            if ($conteudo === false) {
                continue;
            }
            $linhas = [];
            foreach ((new SimpleXMLElement($conteudo))->sheetData->row as $row) {
                $valores = [];
                foreach ($row->c as $c) {
                    $indice = $this->indiceColuna((string) $c['r']);
                    $tipo = (string) $c['t'];
                    $valores[$indice] = match ($tipo) {
                        's' => $compartilhadas[(int) $c->v] ?? '',
                        'inlineStr' => (string) $c->is->t,
                        default => (string) $c->v,
                    };
                }
                if ($valores) {
                    $max = max(array_keys($valores));
                    $linhas[] = array_map(fn ($i) => $valores[$i] ?? '', range(0, $max));
                }
            }
            $abas[(string) $folha['name']] = $linhas;
        }
        $zip->close();

        return $abas;
    }

    private function indiceColuna(string $ref): int
    {
        $letras = preg_replace('/\d/', '', $ref);
        $n = 0;
        foreach (str_split($letras) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return $n - 1;
    }
}
