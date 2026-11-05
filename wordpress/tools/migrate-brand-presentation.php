<?php
/** Gordey_ap01. wp eval-file tools/migrate-brand-presentation.php [apply] --user=<administrator> */
use Service101\Catalog;
global $wpdb;
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_s101_catalog')) {
    throw new RuntimeException('Run as a catalog administrator on staging only.');
}
$plan=[]; $review=[]; $usage=Catalog::brand_usage();
foreach (Catalog::terms('s101_brand') as $brand) {
    // Existing brand copy, including an intentionally cleared field, is editor-owned.
    if (metadata_exists('term',$brand->term_id,'s101_catalog_intro')) { continue; }
    $descriptions=[];
    foreach (Catalog::brand_categories($brand,$usage) as $slug) {
        $text=Catalog::category($slug)['intro'];
        if (trim($text)!=='') { $descriptions[$text]=true; }
    }
    if (count($descriptions)===1) { $plan[$brand->term_id]=['brand'=>$brand->name,'text'=>array_key_first($descriptions)]; }
    else { $review[]=$brand->name; }
}
$apply=($args[0]??'')==='apply';
if ($apply && $plan) {
    Catalog::assert_db($wpdb->query('START TRANSACTION'));
    try {
        $wpdb->get_var('SELECT revision FROM '.Catalog::table('state').' WHERE id=1 FOR UPDATE');
        foreach ($plan as $id=>$row) {
            $saved=add_term_meta($id,'s101_catalog_intro',$row['text'],true);
            if ($saved===false || is_wp_error($saved)) { throw new RuntimeException('Brand description could not be saved.'); }
        }
        Catalog::assert_db($wpdb->query('UPDATE '.Catalog::table('state').' SET revision=revision+1 WHERE id=1'));
        Catalog::assert_db($wpdb->query('COMMIT'));
    } catch (Throwable $error) { $wpdb->query('ROLLBACK'); wp_cache_flush(); throw $error; }
}
echo wp_json_encode(['applied'=>$apply,'copied'=>array_column($plan,'brand'),'using_neutral_default'=>$review,'note'=>'Original category and model descriptions remain in the database.'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
