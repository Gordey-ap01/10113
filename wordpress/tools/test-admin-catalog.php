<?php
/** Run with wp eval-file --user=<administrator> on staging. All fixtures roll back. */
use Service101\Catalog;
use Service101\Import;
use Service101\Workbook;
global $wpdb;

if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
function s101_admin_check(bool $condition,string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message."\n";
}
function s101_admin_reject(callable $action,string $message): void {
    $rejected=false;
    try { $action(); } catch (InvalidArgumentException|RuntimeException $error) { $rejected=true; }
    s101_admin_check($rejected,$message);
}

$baseline=Catalog::devices(true); $revision=Catalog::revision();
s101_admin_reject(static fn()=>Catalog::save_term('s101_category',['name'=>'QA stale','revision'=>$revision-1]),'stale term form rejected before writing');
s101_admin_check(Catalog::revision()===$revision,'failed term edit leaves catalog revision unchanged');
$categories=array_column(Catalog::terms('s101_category'),'slug');
$usage=Catalog::brand_usage($baseline);
foreach (Catalog::terms('s101_brand') as $term) {
    $used=$usage[$term->slug]??[]; $other=array_values(array_diff($categories,$used));
    if (!$used || !$other) { continue; }
    s101_admin_reject(static fn()=>Catalog::save_term('s101_brand',['term_id'=>$term->term_id,'name'=>$term->name,'category_slugs'=>$other,'revision'=>$revision]),'cannot remove a category with existing models');
    s101_admin_check(Catalog::revision()===$revision,'failed relation change leaves revision unchanged');
    break;
}

$token='qa-'.strtolower(wp_generate_password(8,false,false));
$temp=wp_tempnam('s101-admin-workbook');
$wpdb->query('START TRANSACTION');
try {
    $category=wp_insert_term('QA category '.$token,'s101_category',['slug'=>$token]);
    $other_category=wp_insert_term('QA other '.$token,'s101_category',['slug'=>$token.'-other']);
    $brand=wp_insert_term('QA brand '.$token,'s101_brand',['slug'=>$token.'_brand']);
    $second_brand=wp_insert_term('QA second '.$token,'s101_brand',['slug'=>$token.'-second']);
    foreach ([$category,$other_category,$brand,$second_brand] as $created) { if (is_wp_error($created)) { throw new RuntimeException($created->get_error_message()); } }
    $brand_term=get_term($brand['term_id'],'s101_brand');
    $second_term=get_term($second_brand['term_id'],'s101_brand');
    update_term_meta($brand_term->term_id,'s101_category_slugs',[$token,$token.'-other']);
    update_term_meta($brand_term->term_id,'s101_category_orders',[$token=>100,$token.'-other'=>0]);
    update_term_meta($second_term->term_id,'s101_category_slugs',[$token]);
    update_term_meta($second_term->term_id,'s101_category_orders',[$token=>0]);
    update_term_meta($category['term_id'],'s101_sort_order',0);
    update_term_meta($category['term_id'],'s101_home_enabled','0');
    s101_admin_check(Catalog::category($token)['sort_order']===0 && !Catalog::category($token)['home_enabled'],'zero weights and disabled home flag are preserved');
    s101_admin_check(Catalog::brand_order($brand_term,$token)===100 && Catalog::brand_order($brand_term,$token.'-other')===0,'same brand has separate category weights');

    $codes=[];
    foreach ([['draft',$brand_term,100],['private',$brand_term,0],['publish',$second_term,1000]] as $i=>[$status,$term,$weight]) {
        $code=strtoupper(str_replace('-','',$token)).$i; $codes[]=$code;
        $data=Import::device(['code'=>$code,'name'=>'QA model '.$i.' '.$token,'category'=>'stale category name','category_slug'=>$token,'brand'=>'stale brand name','brand_slug'=>$term->slug,'publication'=>Catalog::label($status),'model_order'=>(string)$weight],null);
        $post_id=wp_insert_post(['post_type'=>'s101_device','post_title'=>$data['name'],'post_status'=>$status],true);
        if (is_wp_error($post_id)) { throw new RuntimeException($post_id->get_error_message()); }
        $data['post_id']=$post_id;
        Catalog::assert_db($wpdb->insert(Catalog::table('devices'),['code'=>$code,'post_id'=>$post_id,'path'=>$data['path'],'data'=>Catalog::json($data)]));
        wp_set_object_terms($post_id,[(int)$category['term_id']],'s101_category');
        wp_set_object_terms($post_id,[(int)$term->term_id],'s101_brand');
    }
    $all=Catalog::devices(true); $count=Catalog::model_counts('s101_category')[$token];
    s101_admin_check($count===['total'=>3,'published'=>1],'model count includes draft, private and published devices');
    $brand_count=Catalog::model_counts('s101_brand')[$brand_term->slug];
    s101_admin_check($brand_count===['total'=>2,'published'=>0],'brand count is not the WordPress published-only term count');
    $ordered=array_keys(array_filter($all,static fn($d)=>$d['category_slug']===$token));
    s101_admin_check($ordered===[$codes[2],$codes[1],$codes[0]],'brand weight then model weight control frontend order');
    s101_admin_check(isset(Catalog::devices(false)[$codes[2]]) && !isset(Catalog::devices(false)[$codes[0]]) && !isset(Catalog::devices(false)[$codes[1]]),'public device filtering is unchanged');
    $selected=Catalog::selected_device_terms(['category_slug'=>$token,'brand_slug'=>$brand_term->slug,'category'=>'wrong','brand'=>'wrong']);
    s101_admin_check($selected['category']==='QA category '.$token && $selected['brand']===$brand_term->name,'admin category and brand names come from the selected directory');
    s101_admin_reject(static fn()=>Catalog::selected_device_terms(['category_slug'=>$token.'-other','brand_slug'=>$second_term->slug]),'brand from another category rejected server-side');
    s101_admin_reject(static fn()=>Catalog::selected_device_terms(['category_slug'=>'missing','brand_slug'=>$brand_term->slug]),'unknown category rejected server-side');
    s101_admin_reject(static fn()=>Import::device(['model_order'=>'-1'],$all[$codes[0]]),'negative model order rejected by shared importer');
    s101_admin_check(Import::device(['model_order'=>'0'],$all[$codes[0]])['model_order']===0,'zero model weight survives shared import');
    s101_admin_check(Import::device(['brand'=>'outdated name'],$all[$codes[0]])['brand']===$brand_term->name,'Excel cannot overwrite directory-owned brand name');
    s101_admin_check(Import::device([],$all[$codes[0]])['brand_slug']===$brand_term->slug,'legacy underscore slug remains unchanged');

    Workbook::export($temp);
    $roundtrip=Workbook::read($temp);
    $match=array_values(array_filter($roundtrip['devices'],static fn($d)=>$d['code']===$codes[1]));
    s101_admin_check(count($match)===1 && $match[0]['model_order']==='0','Excel exports and reads zero model weight');
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($temp);
    $book->getSheetByName('Устройства')->removeColumn('O');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->setPreCalculateFormulas(false)->save($temp);
    $book->disconnectWorksheets();
    $legacy=Workbook::read($temp);
    s101_admin_check(count($legacy['devices'])===count($all) && !array_key_exists('model_order',$legacy['devices'][0]),'original 14-column Excel books remain accepted');
} finally {
    $wpdb->query('ROLLBACK');
    wp_cache_flush();
    wp_delete_file($temp);
}
s101_admin_check(Catalog::devices(true)===$baseline && Catalog::revision()===$revision,'test rolls back all temporary catalog data');
