<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$client = app(\App\Services\Shopee\ShopeeClient::class);
function call_safe($label, $fn) {
    try { return ['ok'=>true, 'label'=>$label, 'data'=>$fn()]; }
    catch (Throwable $e) { return ['ok'=>false, 'label'=>$label, 'error'=>get_class($e).': '.$e->getMessage()]; }
}
$tz = new DateTimeZone('America/Sao_Paulo');
$base = new DateTimeImmutable('tomorrow 00:00:00', $tz);
$windows = [
  ['00-06', 0, 6], ['00-08', 0, 8], ['00-12', 0, 12], ['00-23', 0, 23],
  ['09-23', 9, 23], ['12-23', 12, 23], ['18-23', 18, 23]
];
$out = ['now_sp'=>(new DateTimeImmutable('now',$tz))->format('Y-m-d H:i:s T'), 'target_date'=>$base->format('Y-m-d'), 'probes'=>[]];
foreach ($windows as [$label,$sh,$eh]) {
    $start = $base->setTime($sh,0,0);
    $end = $base->setTime($eh,0,0);
    $out['probes'][] = [
        'label'=>$label,
        'start_sp'=>$start->format('Y-m-d H:i:s'),
        'end_sp'=>$end->format('Y-m-d H:i:s'),
        'start_time'=>$start->getTimestamp(),
        'end_time'=>$end->getTimestamp(),
        'response'=>call_safe('get_time_slot_id', fn() => $client->get('/api/v2/shop_flash_sale/get_time_slot_id', ['start_time'=>$start->getTimestamp(), 'end_time'=>$end->getTimestamp()])),
    ];
    usleep(250000);
}
// Read discount lists as coupon/discount stacking evidence available in this integration.
$out['discounts'] = [];
foreach (['ongoing','upcoming'] as $status) {
  $out['discounts'][$status] = call_safe('get_discount_list_'.$status, fn() => $client->get('/api/v2/discount/get_discount_list', ['discount_status'=>$status, 'page_no'=>1, 'page_size'=>50]));
}
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";
