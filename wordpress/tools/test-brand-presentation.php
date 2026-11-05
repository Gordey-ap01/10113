<?php
/** Gordey_ap01. Run only on an isolated staging database: wp eval-file ... --user=<administrator>. */
use Service101\Catalog;
use Service101\Admin;
global $wpdb;
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options') || !str_contains(DB_NAME,'brandtest')) {
    throw new RuntimeException('An isolated brandtest staging database and administrator are required.');
}
function s101_brand_check(bool $condition,string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message."\n";
}
$categories=Catalog::terms('s101_category');
$slugs=array_slice(array_column($categories,'slug'),0,2);
$base=['category_slugs'=>$slugs,'category_orders'=>[]];
$token='qa-brand-'.strtolower(wp_generate_password(8,false,false));
$brand=Catalog::save_term('s101_brand',$base+['name'=>$token,'slug'=>$token,'catalog_intro'=>"<b>Общий текст</b>\nВторая строка",'repair_time'=>'<b>от 45 мин</b>','warranty'=>'<i>до 6 мес</i>']);
$other=Catalog::save_term('s101_brand',$base+['name'=>$token.'-other','slug'=>$token.'-other']);
$settings=Catalog::brand_presentation($brand->slug);
s101_brand_check($settings===['catalog_intro'=>"Общий текст\nВторая строка",'repair_time'=>'от 45 мин','warranty'=>'до 6 мес'],'brand fields persist and strip markup while preserving description newlines');
s101_brand_check(Catalog::brand_presentation($other->slug)['repair_time']==='от 30 мин','another brand retains its own defaults');
foreach ($slugs as $slug) {
    Catalog::save_term('s101_category',['term_id'=>Catalog::term_by_slug('s101_category',$slug)->term_id,'name'=>Catalog::term_by_slug('s101_category',$slug)->name,'catalog_intro'=>'Must not override a brand','repair_time'=>'WRONG','warranty'=>'WRONG']);
}
s101_brand_check(Catalog::brand_presentation($brand->slug)===$settings,'editing either category cannot change shared brand copy or facts');
$before=Catalog::revision(); $rejected=false;
try { Catalog::save_term('s101_brand',$base+['term_id'=>$brand->term_id,'name'=>$brand->name,'warranty'=>['bad'],'revision'=>$before]); }
catch (InvalidArgumentException $error) { $rejected=true; }
s101_brand_check($rejected && Catalog::revision()===$before && Catalog::brand_presentation($brand->slug)===$settings,'malformed input rolls back without changing brand settings or revision');
$rejected=false;
try { Catalog::save_term('s101_brand',$base+['term_id'=>$brand->term_id,'name'=>$brand->name,'warranty'=>'WRONG','revision'=>$before-1]); }
catch (RuntimeException $error) { $rejected=true; }
s101_brand_check($rejected && Catalog::brand_presentation($brand->slug)===$settings,'a stale brand form cannot overwrite newer settings');
$_GET=['page'=>'s101-brands','edit_term'=>$brand->term_id]; ob_start(); Admin::page(); $html=ob_get_clean();
foreach (['catalog_intro','repair_time','warranty'] as $field) {
    s101_brand_check(str_contains($html,'name="term['.$field.']"'),'brand editor exposes '.$field);
}
s101_brand_check(str_contains($html,'name="_wpnonce"'),'brand editor retains nonce protection');
$_GET=['page'=>'s101-categories','edit_term'=>$categories[0]->term_id]; ob_start(); Admin::page(); $html=ob_get_clean();
s101_brand_check(!str_contains($html,'name="term[catalog_intro]"') && !str_contains($html,'name="term[repair_time]"') && !str_contains($html,'name="term[warranty]"'),'category editor no longer owns brand presentation fields');
Catalog::save_term('s101_brand',$base+['term_id'=>$brand->term_id,'name'=>$brand->name,'catalog_intro'=>'','repair_time'=>'','warranty'=>'']);
s101_brand_check(Catalog::brand_presentation($brand->slug)===Catalog::brand_presentation($other->slug),'clearing fields returns to documented brand-independent defaults');
$admin=get_current_user_id(); $subscriber=wp_create_user($token,wp_generate_password(24,true)); wp_set_current_user($subscriber);
$_GET=['page'=>'s101-brands']; ob_start(); Admin::page(); $html=ob_get_clean();
s101_brand_check($html==='' && !current_user_can('manage_s101_catalog'),'a subscriber cannot open the brand editor');
wp_set_current_user($admin);
echo "Brand fixtures remain only in this disposable test database.\n";
