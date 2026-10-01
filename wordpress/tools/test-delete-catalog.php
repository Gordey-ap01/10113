<?php
/** Run with wp eval-file --user=<administrator> on staging. Creates and removes only QA fixtures. */
use Service101\Catalog;
global $wpdb;

if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
function s101_delete_check(bool $condition,string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message."\n";
}

$baseline_revision=Catalog::revision();
$expected_revision=$baseline_revision;
$token='qa-delete-'.strtolower(wp_generate_password(8,false,false));
$category_ids=[]; $brand_id=0; $post_ids=[]; $codes=[]; $service='QADELETE'.strtoupper(substr(md5($token),0,8));
try {
    foreach (['one','two'] as $suffix) {
        $created=wp_insert_term('QA delete '.$suffix,'s101_category',['slug'=>$token.'-'.$suffix]);
        if (is_wp_error($created)) { throw new RuntimeException($created->get_error_message()); }
        $category_ids[$suffix]=(int)$created['term_id'];
    }
    $created=wp_insert_term('QA delete brand','s101_brand',['slug'=>$token.'-brand']);
    if (is_wp_error($created)) { throw new RuntimeException($created->get_error_message()); }
    $brand_id=(int)$created['term_id'];
    update_term_meta($brand_id,'s101_category_slugs',[$token.'-one',$token.'-two']);
    update_term_meta($brand_id,'s101_category_orders',[$token.'-one'=>1000,$token.'-two'=>1000]);
    Catalog::assert_db($wpdb->replace(Catalog::table('services'),['code'=>$service,'name'=>'QA delete service']));

    foreach (['one','two'] as $index=>$suffix) {
        $code='QADELETE'.strtoupper(substr(md5($token.$suffix),0,8)); $codes[$suffix]=$code;
        $post_id=wp_insert_post(['post_type'=>'s101_device','post_title'=>'QA delete '.$suffix,'post_status'=>'draft'],true);
        if (is_wp_error($post_id)) { throw new RuntimeException($post_id->get_error_message()); }
        $post_ids[$suffix]=(int)$post_id;
        $data=['code'=>$code,'name'=>'QA delete '.$suffix,'category'=>'QA delete '.$suffix,'category_slug'=>$token.'-'.$suffix,
            'brand'=>'QA delete brand','brand_slug'=>$token.'-brand','model_slug'=>'model-'.$suffix,'publication'=>'Черновик',
            'model_order'=>1000,'path'=>'/remont/'.$token.'-'.$suffix.'/'.$token.'-brand/model-'.$suffix.'/'];
        Catalog::assert_db($wpdb->insert(Catalog::table('devices'),['code'=>$code,'post_id'=>$post_id,'path'=>$data['path'],'data'=>Catalog::json($data)]));
        Catalog::assert_db($wpdb->insert(Catalog::table('prices'),['device_code'=>$code,'service_code'=>$service,'data'=>Catalog::json(['device_code'=>$code,'service_code'=>$service,'action'=>'Обновить','order'=>$index])]));
        wp_set_object_terms($post_id,[$category_ids[$suffix]],'s101_category');
        wp_set_object_terms($post_id,[$brand_id],'s101_brand');
    }

    $stale_rejected=false;
    try { Catalog::delete_device($codes['one'],$expected_revision-1); } catch (RuntimeException $error) { $stale_rejected=true; }
    s101_delete_check($stale_rejected && (bool)$wpdb->get_var($wpdb->prepare('SELECT code FROM '.Catalog::table('devices').' WHERE code=%s',$codes['one'])),'stale delete form rejected without deleting data');

    Catalog::delete_device($codes['one'],$expected_revision++);
    s101_delete_check(!$wpdb->get_var($wpdb->prepare('SELECT code FROM '.Catalog::table('devices').' WHERE code=%s',$codes['one'])),'device row deleted');
    s101_delete_check(get_post($post_ids['one'])===null,'WordPress device post deleted permanently');
    s101_delete_check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Catalog::table('prices').' WHERE device_code=%s',$codes['one']))===0,'device prices deleted');
    s101_delete_check((bool)$wpdb->get_var($wpdb->prepare('SELECT code FROM '.Catalog::table('services').' WHERE code=%s',$service)),'shared service kept while another device uses it');

    Catalog::delete_term('s101_category',$category_ids['two'],$expected_revision++);
    $deleted_category=get_term($category_ids['two'],'s101_category');
    s101_delete_check(!$deleted_category || is_wp_error($deleted_category),'category deleted');
    s101_delete_check(get_post($post_ids['two'])===null,'category cascade deletes its device');
    s101_delete_check(!$wpdb->get_var($wpdb->prepare('SELECT code FROM '.Catalog::table('services').' WHERE code=%s',$service)),'orphan service removed');
    $remaining=get_term_meta($brand_id,'s101_category_slugs',true);
    s101_delete_check(is_array($remaining) && !in_array($token.'-two',$remaining,true),'deleted category removed from brand relations');

    Catalog::delete_term('s101_brand',$brand_id,$expected_revision++);
    $deleted_brand=get_term($brand_id,'s101_brand');
    s101_delete_check(!$deleted_brand || is_wp_error($deleted_brand),'empty brand deleted');
} finally {
    foreach ($codes as $code) { $wpdb->delete(Catalog::table('prices'),['device_code'=>$code]); $wpdb->delete(Catalog::table('devices'),['code'=>$code]); }
    $wpdb->delete(Catalog::table('services'),['code'=>$service]);
    foreach ($post_ids as $post_id) { if (get_post($post_id)) { wp_delete_post($post_id,true); } }
    foreach ($category_ids as $category_id) { if (get_term($category_id,'s101_category') instanceof WP_Term) { wp_delete_term($category_id,'s101_category'); } }
    if ($brand_id && get_term($brand_id,'s101_brand') instanceof WP_Term) { wp_delete_term($brand_id,'s101_brand'); }
    if (Catalog::revision()===$expected_revision) { $wpdb->update(Catalog::table('state'),['revision'=>$baseline_revision],['id'=>1]); }
    wp_cache_flush();
}
s101_delete_check(Catalog::revision()===$baseline_revision,'catalog revision restored after QA test');
