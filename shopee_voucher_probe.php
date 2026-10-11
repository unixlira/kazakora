<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap();
$client = app(\App\Services\Shopee\ShopeeClient::class);
function safe($label,$fn){try{return ['ok'=>true,'label'=>$label,'data'=>$fn()];}catch(Throwable $e){return ['ok'=>false,'label'=>$label,'error'=>get_class($e).': '.$e->getMessage()];}}
$tests = [
 ['/api/v2/voucher/get_voucher_list', ['voucher_status'=>'ongoing','page_no'=>1,'page_size'=>20]],
 ['/api/v2/voucher/get_voucher_list', ['status'=>'ongoing','page_no'=>1,'page_size'=>20]],
 ['/api/v2/shop_voucher/get_voucher_list', ['voucher_status'=>'ongoing','page_no'=>1,'page_size'=>20]],
 ['/api/v2/shop_voucher/get_voucher_list', ['status'=>'ongoing','page_no'=>1,'page_size'=>20]],
 ['/api/v2/seller_voucher/get_voucher_list', ['voucher_status'=>'ongoing','page_no'=>1,'page_size'=>20]],
];
$out=[]; foreach($tests as [$path,$q]) { $out[] = ['path'=>$path,'query_keys'=>array_keys($q),'response'=>safe($path, fn()=> $client->get($path,$q))]; usleep(200000); }
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
