<?php
/** Create one disposable staging model; verify bulk deletion without touching owner models. */
use Service101\Admin;
use Service101\Catalog;
use Service101\Import;
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
function bulk_check(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } echo 'PASS '.$message."\n"; }
global $wpdb;
$baseline=Catalog::devices(true); $original_prices=Catalog::prices(); $first=reset($baseline);
$sample=array_values(Catalog::prices($first['code']));
if (count($sample)<3) { throw new RuntimeException('Need 3 source services for the bulk test.'); }
$code='UXQA'.strtoupper(substr(str_replace('-','',wp_generate_uuid4()),0,12));
$model=['code'=>$code,'name'=>'QA disposable bulk services','category'=>$first['category'],'category_slug'=>$first['category_slug'],'brand'=>$first['brand'],'brand_slug'=>$first['brand_slug'],'publication'=>'Черновик','model_slug'=>strtolower($code)];
$new_rows=[];
foreach (array_slice($sample,0,3) as $row) { $row['device_code']=$code; $new_rows[]=$row; }
try {
    $plan=Import::preview(['devices'=>[$model],'prices'=>$new_rows]);
    bulk_check(!$plan['errors'],'QA model and three copied service rows validate');
    $batch=Import::save_plan($plan); Import::apply($batch);
    $device=Catalog::devices(true)[$code];
    $rows=array_values(Catalog::prices($code));
    $rows[0]['_delete']='1'; $rows[1]['_delete']='1';
    $plan=Admin::device_plan($device,$rows,Catalog::revision());
    bulk_check(!$plan['errors'] && $plan['counts']['deleted']===2,'two explicitly selected rows produce two deletions');
    $batch=Import::save_plan($plan);
    bulk_check(count(Catalog::prices($code))===3,'saving a preview does not delete selected rows');
    Import::apply($batch);
    $left=Catalog::prices($code);
    bulk_check(count($left)===1 && isset($left[$code.'|'.$rows[2]['service_code']]),'apply removes selected rows and keeps the unselected row');
    foreach ($sample as $row) {
        bulk_check(isset(Catalog::prices($first['code'])[$first['code'].'|'.$row['service_code']]),'shared service stays available on its original model');
        break;
    }
} finally {
    if (isset(Catalog::devices(true)[$code])) { Catalog::delete_device($code,Catalog::revision()); }
    wp_cache_flush();
}
bulk_check(Catalog::devices(true)===$baseline && Catalog::prices()===$original_prices,'temporary QA model removed; all owner models and prices unchanged');
