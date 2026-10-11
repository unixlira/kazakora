<?php
// READ-ONLY Shopee Ads/KazaKora cron probe. No DB writes, no marketplace mutations.
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function ro_money($v) { return round((float)($v ?? 0), 2); }
function ro_api_get($path, $query = []) {
    $account = DB::table('marketplace_accounts')->where('channel','shopee')->first();
    if (!$account || empty($account->access_token)) {
        return ['ok'=>false,'error'=>'Shopee access_token ausente no DB; não tentei renovar por modo read-only.'];
    }
    $partnerId = (int) config('services.shopee.partner_id');
    $partnerKey = (string) config('services.shopee.partner_key');
    $shopId = (int) $account->seller_id;
    $ts = time();
    $base = $partnerId . $path . $ts . $account->access_token . $shopId;
    $sign = hash_hmac('sha256', $base, $partnerKey);
    $params = array_merge([
        'partner_id'=>$partnerId,
        'timestamp'=>$ts,
        'access_token'=>$account->access_token,
        'shop_id'=>$shopId,
        'sign'=>$sign,
    ], $query);
    $url = rtrim((string) config('services.shopee.api_base_url'), '/') . $path . '?' . http_build_query($params);
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_CONNECTTIMEOUT=>10]);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) return ['ok'=>false,'http_status'=>$http,'error'=>'curl: '.$err];
        } else {
            $ctx = stream_context_create(['http'=>['timeout'=>25]]);
            $body = @file_get_contents($url, false, $ctx);
            $http = 0;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $http = (int)$m[1];
            if ($body === false) return ['ok'=>false,'http_status'=>$http,'error'=>'file_get_contents failed'];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) return ['ok'=>false,'http_status'=>$http,'error'=>'resposta não JSON'];
        if ($http >= 400 || !empty($json['error'])) {
            return ['ok'=>false,'http_status'=>$http,'error'=>($json['message'] ?? $json['error'] ?? 'Shopee API error'),'raw_error'=>$json];
        }
        return ['ok'=>true,'http_status'=>$http,'data'=>$json];
    } catch (Throwable $e) {
        return ['ok'=>false,'error'=>get_class($e).': '.$e->getMessage()];
    }
}
function summarize_daily($res) {
    if (!$res['ok']) return $res;
    $row = $res['data']['response'][0] ?? null;
    if (!$row) return ['ok'=>true,'summary'=>null,'raw_count'=>0];
    $spend = ro_money($row['expense'] ?? 0);
    $gmv = ro_money($row['broad_gmv'] ?? 0);
    $clicks = (int)($row['clicks'] ?? 0);
    $orders = (int)($row['broad_order'] ?? 0);
    return ['ok'=>true,'summary'=>[
        'date'=>$row['date'] ?? null,
        'impressions'=>(int)($row['impression'] ?? 0),
        'clicks'=>$clicks,
        'ctr_percent'=>isset($row['ctr']) ? round(((float)$row['ctr'])*100, 2) : null,
        'orders'=>$orders,
        'gmv'=>$gmv,
        'spend'=>$spend,
        'roas'=>$spend > 0 ? round($gmv/$spend, 2) : null,
        'cpc'=>$clicks > 0 ? round($spend/$clicks, 2) : null,
        'cpa'=>$orders > 0 ? round($spend/$orders, 2) : null,
        'clicks_per_order'=>$orders > 0 ? round($clicks/$orders, 1) : null,
        'acos_percent'=>$gmv > 0 ? round(($spend/$gmv)*100, 2) : null,
    ]];
}
function summarize_hourly($res) {
    if (!$res['ok']) return $res;
    $rows = $res['data']['response'] ?? [];
    $total = ['impressions'=>0,'clicks'=>0,'orders'=>0,'gmv'=>0.0,'spend'=>0.0];
    $last = [];
    foreach ($rows as $row) {
        $sp = ro_money($row['expense'] ?? 0);
        $gmv = ro_money($row['broad_gmv'] ?? 0);
        $clicks = (int)($row['clicks'] ?? 0);
        $orders = (int)($row['broad_order'] ?? 0);
        $total['impressions'] += (int)($row['impression'] ?? 0);
        $total['clicks'] += $clicks;
        $total['orders'] += $orders;
        $total['gmv'] += $gmv;
        $total['spend'] += $sp;
        if ($sp > 0 || $clicks > 0 || $orders > 0 || $gmv > 0) {
            $last[] = ['hour'=>$row['hour'] ?? ($row['time'] ?? null),'clicks'=>$clicks,'orders'=>$orders,'gmv'=>$gmv,'spend'=>$sp];
        }
    }
    $total['gmv'] = ro_money($total['gmv']);
    $total['spend'] = ro_money($total['spend']);
    $total['roas'] = $total['spend'] > 0 ? round($total['gmv']/$total['spend'],2) : null;
    return ['ok'=>true,'summary'=>$total,'last_nonzero'=>array_slice($last, -6),'raw_count'=>count($rows)];
}
function summarize_item_base($res, $targets) {
    if (!$res['ok']) return $res;
    $items = [];
    foreach (($res['data']['response']['item_list'] ?? []) as $it) {
        $id = (string)($it['item_id'] ?? '');
        if (!isset($targets[$id])) continue;
        $price = $it['price_info'][0] ?? [];
        $items[$id] = [
            'label'=>$targets[$id]['label'],
            'item_name'=>$it['item_name'] ?? null,
            'status'=>$it['item_status'] ?? null,
            'original_price'=>isset($price['original_price']) ? ro_money($price['original_price']) : null,
            'current_price'=>isset($price['current_price']) ? ro_money($price['current_price']) : null,
            'has_promotion'=>$it['has_promotion'] ?? null,
            'promotion_id'=>$it['promotion_id'] ?? null,
            'seller_stock'=>$it['stock_info_v2']['seller_stock'][0]['stock'] ?? null,
            'available_stock'=>$it['stock_info_v2']['summary_info']['total_available_stock'] ?? null,
        ];
    }
    return ['ok'=>true,'items'=>$items,'warning'=>$res['data']['warning'] ?? null];
}

$tz = config('app.timezone') ?: 'America/Sao_Paulo';
$now = Carbon::now($tz);
$today = $now->copy()->startOfDay();
$tomorrow = $today->copy()->addDay();
$targets = [
  '58213072403' => ['label'=>'Ring Light', 'roas_meta'=>8, 'daily_cap'=>70],
  '58218949032' => ['label'=>'Máquina de Algodão Doce', 'roas_meta'=>8, 'daily_cap'=>60, 'alt_daily_cap'=>30],
  '58266956764' => ['label'=>'Caminhão Unicórnio', 'roas_meta'=>10, 'daily_cap'=>20],
  '58217028672' => ['label'=>'Carregador Samsung', 'roas_meta'=>10, 'daily_cap'=>20],
];
$itemIds = array_keys($targets);
$account = DB::table('marketplace_accounts')->where('channel','shopee')->first();
$listings = DB::table('product_channel_listings as pcl')
    ->leftJoin('products as p','p.id','=','pcl.product_id')
    ->where('pcl.channel','shopee')->whereIn('pcl.external_id',$itemIds)
    ->select('pcl.id as listing_id','pcl.external_id','pcl.external_model_id','pcl.product_id','pcl.status','pcl.is_enabled','p.name','p.sku','p.price','p.cost_price','p.stock')
    ->orderBy('pcl.external_id')->get();
$ordersTarget = collect();
$ordersDetail = collect();
if (Schema::hasColumn('order_items','external_item_id')) {
    $ordersTarget = DB::table('order_items as oi')
      ->join('orders as o','o.id','=','oi.order_id')
      ->leftJoin('products as p','p.id','=','oi.product_id')
      ->where('o.origin','shopee')
      ->where('o.created_at','>=',$today)->where('o.created_at','<',$tomorrow)
      ->whereIn('oi.external_item_id',$itemIds)
      ->whereNotIn('o.status',['cancelled','canceled'])
      ->groupBy('oi.external_item_id')
      ->selectRaw('oi.external_item_id as item_id, COUNT(DISTINCT o.id) as orders, SUM(oi.quantity) as units, SUM(oi.subtotal) as revenue, MAX(o.created_at) as last_order_at, GROUP_CONCAT(DISTINCT oi.product_id ORDER BY oi.product_id) as product_ids, GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR " | ") as skus')
      ->orderByDesc('revenue')->get();
    $ordersDetail = DB::table('order_items as oi')
      ->join('orders as o','o.id','=','oi.order_id')
      ->leftJoin('products as p','p.id','=','oi.product_id')
      ->where('o.origin','shopee')
      ->where('o.created_at','>=',$today)->where('o.created_at','<',$tomorrow)
      ->whereIn('oi.external_item_id',$itemIds)
      ->whereNotIn('o.status',['cancelled','canceled'])
      ->orderByDesc('o.created_at')
      ->get(['o.id as order_id','o.external_order_id','o.created_at','o.subtotal as order_total','oi.product_id','oi.product_name as item_name','oi.external_item_id','oi.external_model_id','oi.product_price','oi.quantity','oi.subtotal as item_subtotal','p.sku','p.cost_price']);
}
$ordersAll = DB::table('orders')->where('origin','shopee')
  ->where('created_at','>=',$today)->where('created_at','<',$tomorrow)
  ->whereNotIn('status',['cancelled','canceled'])
  ->selectRaw('COUNT(*) as orders, COALESCE(SUM(subtotal),0) as revenue, MAX(created_at) as last_order_at')->first();
$adSpendRows = Schema::hasTable('channel_ad_spends') ? DB::table('channel_ad_spends')->where('channel','shopee')->where('date',$today->toDateString())->get() : [];
$campaignMetrics = Schema::hasTable('marketplace_campaign_metrics') ? DB::table('marketplace_campaign_metrics')->where('channel','shopee')->where('date','>=',$today->copy()->subDays(2)->toDateString())->orderByDesc('date')->orderBy('campaign_name')->get() : [];
$settlementAds = null;
if (Schema::hasTable('marketplace_settlement_details')) {
    try {
        $settlementAds = DB::table('marketplace_settlement_details')->where('channel','shopee')
          ->where(function($q) use ($today) {
              if (Schema::hasColumn('marketplace_settlement_details','created_at')) $q->orWhereDate('created_at',$today->toDateString());
              if (Schema::hasColumn('marketplace_settlement_details','order_created_at')) $q->orWhereDate('order_created_at',$today->toDateString());
              if (Schema::hasColumn('marketplace_settlement_details','transaction_date')) $q->orWhereDate('transaction_date',$today->toDateString());
          })
          ->selectRaw('COALESCE(SUM(ABS(gmv_max_ad_fee)),0) as gmv_max_ad_fee, COALESCE(SUM(CASE WHEN transaction_type LIKE "%anúncio%" OR transaction_type LIKE "%anuncio%" THEN ABS(adjustment_amount) ELSE 0 END),0) as ad_adjustments')
          ->first();
    } catch (Throwable $e) { $settlementAds = ['error'=>$e->getMessage()]; }
}
$ds = $today->format('d-m-Y');
$apiBalance = ro_api_get('/api/v2/ads/get_total_balance');
$apiDaily = ro_api_get('/api/v2/ads/get_all_cpc_ads_daily_performance', ['start_date'=>$ds,'end_date'=>$ds]);
$apiHourly = ro_api_get('/api/v2/ads/get_all_cpc_ads_hourly_performance', ['date'=>$ds]);
$apiItems = ro_api_get('/api/v2/product/get_item_base_info', ['item_id_list'=>implode(',', $itemIds)]);
$out = [
  'generated_at'=>$now->toDateTimeString(),
  'timezone'=>$tz,
  'evidence'=>'read-only: DB SELECTs + Shopee signed GETs only; no ShopeeClient token refresh used',
  'expected_caps'=>['sum_cap_machine_60'=>170,'sum_cap_machine_30'=>140],
  'target_items'=>$targets,
  'shopee_account'=>$account ? ['status'=>$account->status,'seller_id'=>$account->seller_id,'token_expires_at'=>$account->token_expires_at] : null,
  'api'=>[
    'balance'=>$apiBalance['ok'] ? ['ok'=>true,'total_balance'=>$apiBalance['data']['response']['total_balance'] ?? null,'data_timestamp'=>$apiBalance['data']['response']['data_timestamp'] ?? null] : $apiBalance,
    'daily_performance_today'=>summarize_daily($apiDaily),
    'hourly_performance_today'=>summarize_hourly($apiHourly),
    'item_base'=>summarize_item_base($apiItems, $targets),
  ],
  'db'=>[
    'orders_shopee_today_total'=>$ordersAll,
    'orders_target_items_today'=>$ordersTarget,
    'orders_target_items_detail'=>$ordersDetail,
    'channel_ad_spends_today'=>$adSpendRows,
    'campaign_metrics_recent_cache'=>$campaignMetrics,
    'settlement_ads_today'=>$settlementAds,
    'target_local_listings'=>$listings,
  ],
];
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
