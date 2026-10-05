<?php
// Naia/Cauê - executor guardado para Oferta Relâmpago Shopee KazaKora.
// APPLY=0: dry-run; APPLY=1: cria somente se slot Shopee válido + sem cupom/desconto vendedor ativo/upcoming + preços >= min_13.
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$client = app(\App\Services\Shopee\ShopeeClient::class);
$apply = getenv('APPLY') === '1';
$planPath = $argv[1] ?? (__DIR__.'/storage/app/naia-audits/shopee_flash_sale_plan_2026-10-01.json');
$statePath = __DIR__.'/storage/app/naia-audits/shopee_flash_sale_2026-10-01.state.json';
$outDir = __DIR__.'/storage/app/naia-audits';
@mkdir($outDir, 0775, true);
function safe_call(string $label, callable $fn): array { try { return ['ok'=>true,'label'=>$label,'data'=>$fn()]; } catch (Throwable $e) { return ['ok'=>false,'label'=>$label,'error'=>get_class($e).': '.$e->getMessage()]; } }
function jdump($v){ return json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function recursive_find_id($data, array $keys): ?int {
    if (!is_array($data)) return null;
    foreach ($data as $k=>$v) {
        if (in_array((string)$k, $keys, true) && is_numeric($v)) return (int)$v;
        if (is_array($v)) { $r = recursive_find_id($v, $keys); if ($r) return $r; }
    }
    return null;
}
function ending_money($v): float { return round((float)$v, 2); }
function stock_for_promo($stock): int { $s = is_numeric($stock) ? (int)$stock : 20; if ($s <= 0) return 10; return max(1, min(20, $s)); }
function has_items_in_response($resp, array $names): bool {
    $r = $resp['data']['response'] ?? $resp['data']['response'] ?? $resp['response'] ?? null;
    if (!is_array($r)) return false;
    foreach ($names as $name) {
        if (!empty($r[$name]) && is_array($r[$name])) return true;
    }
    return false;
}
if (file_exists($statePath)) {
    $state = json_decode(file_get_contents($statePath), true) ?: [];
    if (($state['done'] ?? false) === true) { exit(0); }
}
$plan = json_decode(file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR);
$tz = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $tz);
$target = new DateTimeImmutable(($plan['target_date_sp'] ?? '2026-10-01').' 00:00:00', $tz);
$cutoff = $target->modify('-5 minutes');
$result = ['apply'=>$apply,'now_sp'=>$now->format('Y-m-d H:i:s T'),'plan'=>$planPath,'events'=>[],'created'=>false,'aborted'=>false];
if ($apply && $now > $cutoff) {
    $result['aborted'] = true; $result['reason'] = 'cutoff_passed_before_slot_creation';
    file_put_contents($statePath, jdump(['done'=>true,'reason'=>'cutoff_passed','at'=>$now->format(DateTimeInterface::ATOM)]));
    echo "Oferta Relâmpago Shopee: não criei porque o slot não liberou antes do corte de 23:55 BRT.\n";
    exit(0);
}
// 1) Slot probes, prefer 00-23. If none, stay silent in APPLY mode until cutoff.
$windows = [['00-23',0,23], ['00-12',0,12], ['00-08',0,8], ['00-06',0,6]];
$slot = null;
foreach ($windows as [$label,$sh,$eh]) {
    $start = $target->setTime($sh,0,0); $end = $target->setTime($eh,0,0);
    $probe = ['label'=>$label,'start_sp'=>$start->format('Y-m-d H:i:s'),'end_sp'=>$end->format('Y-m-d H:i:s')];
    $probe['response'] = safe_call('get_time_slot_id', fn() => $client->get('/api/v2/shop_flash_sale/get_time_slot_id', ['start_time'=>$start->getTimestamp(), 'end_time'=>$end->getTimestamp()]));
    $result['events'][] = ['slot_probe'=>$probe];
    if ($probe['response']['ok']) { $slot = ['label'=>$label,'start'=>$start,'end'=>$end,'raw'=>$probe['response']['data']]; break; }
    usleep(200000);
}
if (!$slot) {
    $result['reason'] = 'no_valid_slot_yet';
    if (!$apply) echo jdump($result),"\n";
    exit(0);
}
$timeslotId = recursive_find_id($slot['raw'], ['timeslot_id','time_slot_id','time_slot']);
if (!$timeslotId) { $result['aborted']=true; $result['reason']='slot_response_without_timeslot_id'; echo jdump($result),"\n"; exit(1); }
$result['timeslot_id'] = $timeslotId;
// 2) Cupom/desconto vendedor. Abort if anything active/upcoming appears.
$voucherChecks=[]; foreach(['ongoing','upcoming'] as $status) { $voucherChecks[$status]=safe_call('voucher_'.$status, fn()=> $client->get('/api/v2/voucher/get_voucher_list', ['status'=>$status,'page_no'=>1,'page_size'=>50])); }
$discountChecks=[]; foreach(['ongoing','upcoming'] as $status) { $discountChecks[$status]=safe_call('discount_'.$status, fn()=> $client->get('/api/v2/discount/get_discount_list', ['discount_status'=>$status,'page_no'=>1,'page_size'=>50])); }
$result['voucher_checks']=$voucherChecks; $result['discount_checks']=$discountChecks;
foreach ($voucherChecks as $v) { if ($v['ok'] && has_items_in_response($v, ['voucher_list'])) { $result['aborted']=true; $result['reason']='seller_voucher_present'; echo jdump($result),"\n"; exit(2); } }
foreach ($discountChecks as $d) { if ($d['ok'] && has_items_in_response($d, ['discount_list','discount'])) { $result['aborted']=true; $result['reason']='seller_discount_present'; echo jdump($result),"\n"; exit(2); } }
// 3) Build item payloads with live price/model/stock readback.
$itemGroups = [];
foreach (($plan['candidates'] ?? []) as $c) {
    $itemId = (int)$c['item_id']; $sku = trim((string)$c['sku']); $promo = ending_money($c['promo_price']); $min13 = ending_money($c['min_13']);
    if ($promo + 0.0001 < $min13) { $result['events'][]=['skip'=>$sku,'reason'=>'promo_below_min13']; continue; }
    $base = safe_call('get_item_base_info', fn()=> $client->get('/api/v2/product/get_item_base_info', ['item_id_list'=>(string)$itemId,'response_optional_fields'=>'price_info,stock_info_v2,item_sku']));
    if (!$base['ok']) { $result['events'][]=['skip'=>$sku,'reason'=>'base_error','error'=>$base['error']]; continue; }
    $item = $base['data']['response']['item_list'][0] ?? [];
    $hasModel = (bool)($item['has_model'] ?? false);
    if (!isset($itemGroups[$itemId])) $itemGroups[$itemId] = ['item_id'=>$itemId,'models'=>[], 'single'=>null];
    if ($hasModel) {
        $modelsCall = safe_call('get_model_list', fn()=> $client->get('/api/v2/product/get_model_list', ['item_id'=>$itemId]));
        if (!$modelsCall['ok']) { $result['events'][]=['skip'=>$sku,'reason'=>'model_error','error'=>$modelsCall['error']]; continue; }
        $found = null;
        foreach (($modelsCall['data']['response']['model'] ?? []) as $m) { if (trim((string)($m['model_sku'] ?? '')) === $sku) { $found=$m; break; } }
        if (!$found) { $result['events'][]=['skip'=>$sku,'reason'=>'model_sku_not_found']; continue; }
        $stock = $found['stock_info_v2']['summary_info']['total_available_stock'] ?? null;
        $itemGroups[$itemId]['models'][] = ['model_id'=>(int)$found['model_id'], 'input_promo_price'=>$promo, 'stock'=>stock_for_promo($stock)];
    } else {
        $stock = $item['stock_info_v2']['summary_info']['total_available_stock'] ?? null;
        $itemGroups[$itemId]['single'] = ['item_id'=>$itemId, 'item_input_promo_price'=>$promo, 'item_stock'=>stock_for_promo($stock), 'purchase_limit'=>5];
    }
}
$items=[];
foreach($itemGroups as $g) {
    if (!empty($g['models'])) $items[]=['item_id'=>$g['item_id'], 'models'=>$g['models'], 'purchase_limit'=>5];
    elseif (!empty($g['single'])) $items[]=$g['single'];
}
$result['payload_items_count'] = count($items);
$result['payload_model_count'] = array_sum(array_map(fn($it)=> isset($it['models']) ? count($it['models']) : 1, $items));
if (count($items) === 0) { $result['aborted']=true; $result['reason']='no_valid_items_after_readback'; echo jdump($result),"\n"; exit(3); }
if (!$apply) { echo jdump($result),"\n"; exit(0); }
// 4) Create + add.
$create = safe_call('create_shop_flash_sale', fn()=> $client->post('/api/v2/shop_flash_sale/create_shop_flash_sale', ['timeslot_id'=>$timeslotId]));
$result['create_response']=$create;
if (!$create['ok']) { $result['aborted']=true; $result['reason']='create_failed'; echo jdump($result),"\n"; exit(4); }
$flashSaleId = recursive_find_id($create['data'], ['flash_sale_id','shop_flash_sale_id','promotion_id']);
$result['flash_sale_id']=$flashSaleId;
if (!$flashSaleId) { $result['aborted']=true; $result['reason']='create_response_without_flash_sale_id'; echo jdump($result),"\n"; exit(5); }
$add = safe_call('add_shop_flash_sale_items', fn()=> $client->post('/api/v2/shop_flash_sale/add_shop_flash_sale_items', ['flash_sale_id'=>$flashSaleId, 'items'=>$items]));
$result['add_response']=$add;
$read1 = safe_call('get_shop_flash_sale', fn()=> $client->get('/api/v2/shop_flash_sale/get_shop_flash_sale', ['flash_sale_id'=>$flashSaleId]));
$read2 = safe_call('get_shop_flash_sale_items', fn()=> $client->get('/api/v2/shop_flash_sale/get_shop_flash_sale_items', ['flash_sale_id'=>$flashSaleId,'offset'=>0,'page_size'=>50]));
$result['readback_campaign']=$read1; $result['readback_items']=$read2;
$result['created']=$add['ok'];
$stamp = (new DateTimeImmutable('now',$tz))->format('Ymd_His');
$outPath = $outDir.'/shopee_flash_sale_execution_'.$stamp.'.json';
file_put_contents($outPath, jdump($result));
file_put_contents($statePath, jdump(['done'=>true,'at'=>$now->format(DateTimeInterface::ATOM),'flash_sale_id'=>$flashSaleId,'out'=>$outPath,'created'=>$result['created']]));
if ($result['created']) {
    echo "✅ Oferta Relâmpago Shopee criada. flash_sale_id={$flashSaleId}; itens_payload={$result['payload_model_count']}; auditoria={$outPath}\n";
} else {
    echo "Oferta Relâmpago Shopee: campanha criada, mas inclusão de itens falhou. flash_sale_id={$flashSaleId}; auditoria={$outPath}\n";
}
