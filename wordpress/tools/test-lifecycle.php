<?php
/** Reversible integration checks on the isolated staging catalog. */
use Service101\Catalog;
use Service101\Import;
use Service101\Images;
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
function verify_s101(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } echo 'PASS '.$message."\n"; }
$devices=Catalog::devices(true); $prices=Catalog::prices(); $first=reset($devices); $first_price=reset($prices);
$mixed=$first; $mixed['code']=strtolower($mixed['code']);
$plan=Import::preview(['devices'=>[$first,$mixed],'prices'=>[]]);
verify_s101(count($plan['errors'])===1,'case variants cannot overwrite or duplicate a code');
$new=['code'=>'dqa101','name'=>'QA temporary model','category'=>'Телефоны','category_slug'=>'telefony','brand'=>'QA','brand_slug'=>'qa','publication'=>'Опубликовать','description'=>'Temporary staging test'];
$price=$first_price; $price['device_code']='dqa101'; $price['work_type']='Фиксированная'; $price['work_amount']='1234.56';
$plan=Import::preview(['devices'=>[$new],'prices'=>[$price]]);
verify_s101(!$plan['errors'],'new model and copied service validate');
$batch=Import::save_plan($plan); Import::apply($batch);
try {
    $current=Catalog::devices(true);
    verify_s101(isset($current['DQA101']) && count($current)===count($devices)+1,'new model gets a permanent code and WordPress page');
    verify_s101(isset(Catalog::devices(false)['DQA101']),'explicit publication makes model publicly eligible');
    verify_s101(Catalog::prices('DQA101')['DQA101|'.$price['service_code']]['work_amount']==='1234.56','copied service receives the new model price');
} finally { Import::restore($batch); }
verify_s101(Catalog::devices(true)===$devices && Catalog::prices()===$prices,'rollback removes temporary model and restores original catalog');
$changed=$first; $changed['image1']='[очистить]'; $changed['description']='[очистить]';
$hidden=$first_price; $hidden['action']='Скрыть';
$plan=Import::preview(['devices'=>[$changed],'prices'=>[$hidden]]); $batch=Import::save_plan($plan); Import::apply($batch);
try {
    $current=Catalog::devices(true)[$first['code']];
    verify_s101($current['image1']==='' && $current['image1_id']===0 && !get_post_thumbnail_id($current['post_id']),'explicit image clearing updates catalog and thumbnail');
    verify_s101(count(Catalog::prices($first['code'],false))===count(array_filter($prices,static fn($p)=>$p['device_code']===$first['code']))-1,'hidden service is excluded from public prices');
} finally { Import::restore($batch); }
$deny=static function(array $caps): array { $caps['publish_s101_devices']=false; $caps['manage_s101_prices']=false; return $caps; };
add_filter('user_has_cap',$deny);
try { $plan=Import::preview(['devices'=>[$new],'prices'=>[$price]]); verify_s101(count($plan['errors'])>=2,'publication and price permissions are enforced'); }
finally { remove_filter('user_has_cap',$deny); }
foreach (['https://127.0.0.1/private.png','https://localhost/private.png','http://example.com/image.png','https://example.com:8443/image.png'] as $url) {
    $blocked=false; try { Images::prepare($url); } catch (Throwable) { $blocked=true; }
    verify_s101($blocked,'unsafe image destination rejected');
}
verify_s101(Catalog::devices(true)===$devices && Catalog::prices()===$prices,'all original devices, prices and visibility restored');
