<?php

/**
 * Importa o extrato de receita do TikTok Shop pra tabela
 * marketplace_settlement_details, que é a base do "Extratos reais de
 * Marketplace" no Dashboard Financeiro.
 *
 * Uso (na raiz do projeto, no servidor):
 *   php scripts/import_tiktok_income.php /caminho/export.json [--dry-run]
 *
 * O JSON é a conversão do relatório de receita que o canal exporta; as
 * chaves são os títulos das colunas do relatório, em português, do jeito
 * que o TikTok manda. Reimportar o mesmo arquivo não duplica: a linha é
 * identificada por canal+demonstrativo+pedido+SKU+tipo de transação
 * (unique em marketplace_settlement_details).
 *
 * Veio de um arquivo solto na raiz do servidor (2026-09-13), que o deploy
 * apagaria no próximo rsync --delete — está aqui pra não se perder.
 */

use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$jsonPath = $argv[1] ?? null;
$dryRun = in_array('--dry-run', $argv, true);
if (! $jsonPath || ! is_file($jsonPath)) {
    fwrite(STDERR, "Uso: php scripts/import_tiktok_income.php /caminho/export.json [--dry-run]\n");
    exit(2);
}

$payload = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
$rows = $payload['rows'] ?? [];
$sourceFile = $payload['summary']['source_file'] ?? basename($jsonPath);

function v(array $row, string $key, $default = null) {
    $value = $row[$key] ?? $default;
    return $value === '' ? $default : $value;
}
function money(array $row, string $key): float {
    $value = v($row, $key, 0);
    if ($value === '/' || $value === null || $value === '') {
        return 0.0;
    }
    return round((float) str_replace(',', '.', (string) $value), 2);
}
function intv(array $row, string $key): int {
    $value = v($row, $key, 0);
    if ($value === '/' || $value === null || $value === '') {
        return 0;
    }
    return (int) $value;
}
function datev(array $row, string $key): ?string {
    $value = v($row, $key);
    if (! $value || $value === '/') {
        return null;
    }
    return str_replace('/', '-', substr((string) $value, 0, 10));
}
function idv(array $row, string $key): string {
    $value = v($row, $key, '/');
    $value = trim((string) $value);
    return $value === '' ? '/' : $value;
}

$before = DB::table('marketplace_settlement_details')->where('channel', 'tiktok_shop')->count();
$matchedBefore = DB::table('marketplace_settlement_details as s')
    ->join('orders as o', function ($join) {
        $join->on('o.external_order_id', '=', 's.external_order_id')
            ->on('o.origin', '=', 's.channel');
    })
    ->where('s.channel', 'tiktok_shop')
    ->where('s.transaction_type', 'Pedido')
    ->distinct('s.external_order_id')
    ->count('s.external_order_id');

$inserted = 0;
$updated = 0;
$seenKeys = [];
$duplicatesInFile = 0;

$summary = DB::transaction(function () use ($rows, $sourceFile, $dryRun, &$inserted, &$updated, &$seenKeys, &$duplicatesInFile) {
    foreach ($rows as $row) {
        $key = [
            'channel' => 'tiktok_shop',
            'statement_id' => idv($row, 'ID do demonstrativo'),
            'external_order_id' => idv($row, 'ID do pedido/ajuste'),
            'external_sku_id' => idv($row, 'ID do SKU'),
            'transaction_type' => (string) v($row, 'Tipo de transação', 'Pedido'),
        ];
        $keyString = implode('|', $key);
        if (isset($seenKeys[$keyString])) {
            $duplicatesInFile++;
            continue;
        }
        $seenKeys[$keyString] = true;

        $values = [
            'settlement_date' => datev($row, 'Data do demonstrativo'),
            'payment_id' => idv($row, 'ID do pagamento'),
            'status' => (string) v($row, 'Status', ''),
            'currency' => (string) v($row, 'Moeda', 'BRL'),
            'quantity' => max(0, intv($row, 'Quantidade')),
            'product_name' => v($row, 'Nome do produto'),
            'sku_name' => v($row, 'Nome do SKU'),
            'order_created_at' => datev($row, 'Data de criação do pedido'),
            'order_delivered_at' => datev($row, 'Data de entrega do pedido'),
            'payout_amount' => money($row, 'Valor total a ser liquidado'),
            'product_net_sales' => money($row, 'Vendas líquidas dos produtos'),
            'item_subtotal_before_discounts' => money($row, 'Subtotal do item antes dos descontos'),
            'seller_discounts' => money($row, 'Descontos financiados pelo vendedor'),
            'platform_product_discounts' => money($row, 'Desconto da plataforma'),
            'platform_coupon_discounts' => money($row, 'Desconto de cupom cofinanciado pela plataforma'),
            'platform_coupon_discount_refunds' => money($row, 'Reembolso de desconto do cupom cofinanciado pela plataforma'),
            'product_refunds' => money($row, 'Reembolsos de produtos'),
            'net_shipping_cost' => money($row, 'Custo líquido de frete'),
            'shipping_cost' => money($row, 'Custo do frete'),
            'customer_shipping_fee' => money($row, 'Taxa de frete paga pelo cliente'),
            'channel_shipping_coverage' => money($row, 'Custo de frete coberto pelo TikTok Shop'),
            'platform_shipping_discounts' => money($row, 'Desconto na taxa de envio do TikTok Shop para o cliente'),
            'return_shipping_cost' => money($row, 'Custo de frete para devoluções'),
            'platform_fees_taxes' => money($row, 'Taxas e impostos'),
            'platform_commission_fee' => money($row, 'Tarifa de comissão da plataforma'),
            'service_fees' => money($row, 'Taxas de serviço'),
            'sfp_service_fee' => money($row, 'Taxa de serviço do SFP'),
            'taxes' => money($row, 'Impostos'),
            'icms_difal' => money($row, 'ICMS DIFAL'),
            'icms_fine' => money($row, 'Multa de ICMS'),
            'affiliate_commissions' => money($row, 'Comissões de afiliados'),
            'estimated_affiliate_commissions' => money($row, 'Comissão de afiliados estimada'),
            'agency_partner_commission' => money($row, 'Comissão paga às agências parceiras'),
            'shop_ads_creator_commission' => money($row, 'Comissão de Anúncios da loja paga aos criadores'),
            'shop_ads_agency_commission' => money($row, 'Comissão de Anúncios da loja paga às agências parceiras'),
            'gmv_max_ad_fee' => money($row, 'Taxa de anúncio de GMV Max'),
            'adjustment_amount' => money($row, 'Valor do ajuste'),
            'adjustment_reason' => v($row, 'Motivo do ajuste'),
            'related_order_id' => idv($row, 'ID do pedido relacionado'),
            'customer_payment' => money($row, 'Pagamento do cliente'),
            'source_file' => $sourceFile,
            'updated_at' => now(),
        ];

        $exists = DB::table('marketplace_settlement_details')->where($key)->exists();
        if ($exists) {
            $updated++;
        } else {
            $inserted++;
            $values['created_at'] = now();
        }
        if (! $dryRun) {
            DB::table('marketplace_settlement_details')->updateOrInsert($key, $values);
        }
    }

    $orderRows = DB::table('marketplace_settlement_details as s')
        ->join('orders as o', function ($join) {
            $join->on('o.external_order_id', '=', 's.external_order_id')
                ->on('o.origin', '=', 's.channel');
        })
        ->where('s.channel', 'tiktok_shop')
        ->where('s.transaction_type', 'Pedido')
        ->whereIn('s.external_order_id', collect($rows)->pluck('ID do pedido/ajuste')->filter()->unique()->all())
        ->groupBy('o.id', 'o.origin')
        ->selectRaw('o.id as order_id, o.origin as channel, COALESCE(SUM(s.product_net_sales), 0) as gross_amount, ABS(COALESCE(SUM(s.platform_fees_taxes), 0)) as fee_amount')
        ->get();

    $feeRows = 0;
    foreach ($orderRows as $fee) {
        if (! $dryRun) {
            OrderChannelFee::query()->updateOrCreate(
                ['order_id' => $fee->order_id, 'channel' => $fee->channel],
                [
                    'gross_amount' => round((float) $fee->gross_amount, 2),
                    'fee_amount' => round((float) $fee->fee_amount, 2),
                    'source' => OrderChannelFee::SOURCE_REPORT,
                    'computed_at' => now(),
                ]
            );
        }
        $feeRows++;
    }

    $pedidoRows = collect($rows)->filter(fn ($row) => ($row['Tipo de transação'] ?? null) === 'Pedido');
    $productNet = round($pedidoRows->sum(fn ($row) => money($row, 'Vendas líquidas dos produtos')), 2);
    $rates = [
        'source_file' => $sourceFile,
        'calculated_at' => now()->toIso8601String(),
        'basis' => 'Observed averages from TikTok Shop Income export; not a fixed contract rate.',
        'pedido_rows' => $pedidoRows->count(),
        'product_net_sales' => $productNet,
        'platform_fees_taxes_rate' => $productNet > 0 ? round(abs($pedidoRows->sum(fn ($row) => money($row, 'Taxas e impostos'))) / $productNet, 6) : 0,
        'service_fees_rate' => $productNet > 0 ? round(abs($pedidoRows->sum(fn ($row) => money($row, 'Taxas de serviço'))) / $productNet, 6) : 0,
        'sfp_service_fee_rate' => $productNet > 0 ? round(abs($pedidoRows->sum(fn ($row) => money($row, 'Taxa de serviço do SFP'))) / $productNet, 6) : 0,
        'affiliate_commissions_rate' => $productNet > 0 ? round(abs($pedidoRows->sum(fn ($row) => money($row, 'Comissões de afiliados'))) / $productNet, 6) : 0,
        'seller_discounts_rate' => $productNet > 0 ? round(abs($pedidoRows->sum(fn ($row) => money($row, 'Descontos financiados pelo vendedor'))) / $productNet, 6) : 0,
        'net_shipping_debit_rate' => $productNet > 0 ? round(abs($pedidoRows->filter(fn ($row) => money($row, 'Custo líquido de frete') < 0)->sum(fn ($row) => money($row, 'Custo líquido de frete'))) / $productNet, 6) : 0,
    ];

    if (! $dryRun) {
        DB::table('settings')->updateOrInsert(
            ['key' => 'tiktok_shop_settlement_observed_rates'],
            ['value' => json_encode($rates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    return ['fee_rows_synced' => $feeRows, 'observed_rates' => $rates];
});

$after = DB::table('marketplace_settlement_details')->where('channel', 'tiktok_shop')->count();
$statementIds = collect($rows)->pluck('ID do demonstrativo')->filter()->unique()->values();
$sourceSummary = DB::table('marketplace_settlement_details')
    ->where('channel', 'tiktok_shop')
    ->whereIn('statement_id', $statementIds->all())
    ->selectRaw('COUNT(*) as line_items,
        COUNT(DISTINCT CASE WHEN transaction_type = \'Pedido\' THEN NULLIF(external_order_id, \'/\') END) as order_count,
        COALESCE(SUM(payout_amount), 0) as payout,
        COALESCE(SUM(product_net_sales), 0) as product_net_sales,
        COALESCE(SUM(net_shipping_cost), 0) as net_shipping,
        COALESCE(SUM(platform_fees_taxes), 0) as fees_taxes,
        COALESCE(SUM(adjustment_amount), 0) as adjustments')
    ->first();
$matchedAfter = DB::table('marketplace_settlement_details as s')
    ->join('orders as o', function ($join) {
        $join->on('o.external_order_id', '=', 's.external_order_id')
            ->on('o.origin', '=', 's.channel');
    })
    ->where('s.channel', 'tiktok_shop')
    ->where('s.transaction_type', 'Pedido')
    ->whereIn('s.statement_id', $statementIds->all())
    ->distinct('s.external_order_id')
    ->count('s.external_order_id');
$missing = DB::table('marketplace_settlement_details as s')
    ->leftJoin('orders as o', function ($join) {
        $join->on('o.external_order_id', '=', 's.external_order_id')
            ->on('o.origin', '=', 's.channel');
    })
    ->where('s.channel', 'tiktok_shop')
    ->where('s.transaction_type', 'Pedido')
    ->whereIn('s.statement_id', $statementIds->all())
    ->whereNull('o.id')
    ->distinct()
    ->pluck('s.external_order_id')
    ->take(20)
    ->values();

$out = [
    'dry_run' => $dryRun,
    'source_file' => $sourceFile,
    'input_rows' => count($rows),
    'unique_rows_in_file' => count($seenKeys),
    'duplicates_in_file_skipped' => $duplicatesInFile,
    'inserted_estimate' => $dryRun ? $inserted : max(0, $after - $before),
    'updated_estimate' => $updated,
    'tiktok_lines_before' => $before,
    'tiktok_lines_after' => $after,
    'matched_orders_before_all_tiktok' => $matchedBefore,
    'matched_orders_in_imported_statements' => $matchedAfter,
    'missing_orders_in_imported_statements_count_sample' => $missing->count(),
    'missing_orders_sample' => $missing,
    'statement_summary' => $sourceSummary,
    'fee_rows_synced' => $summary['fee_rows_synced'],
    'observed_rates' => $summary['observed_rates'],
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
