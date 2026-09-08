<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
add_filter('show_admin_bar','__return_false');
add_action('after_setup_theme',static function(){ add_theme_support('title-tag'); add_theme_support('post-thumbnails'); add_theme_support('html5',['search-form','gallery','caption','style','script']); });
remove_action('wp_head','wp_generator');
remove_action('wp_head','rest_output_link_wp_head');
remove_action('wp_head','wp_oembed_add_discovery_links');
remove_action('wp_head','rel_canonical');
add_action('wp_enqueue_scripts',static function(){
    $uri=get_stylesheet_directory_uri();
    $files=['styles.css','styles-10107.css','styles-10110.css'];
    if (is_front_page()) { array_splice($files,2,0,['styles-10108.css']); }
    foreach ($files as $file) { wp_enqueue_style('s101-'.sanitize_key($file),$uri.'/'.$file,[],filemtime(get_stylesheet_directory().'/'.$file)); }
    wp_enqueue_script('s101-app',$uri.'/scripts/app.js',[],filemtime(get_stylesheet_directory().'/scripts/app.js'),['strategy'=>'defer','in_footer'=>true]);
    wp_dequeue_style('wp-block-library'); wp_dequeue_style('global-styles'); wp_dequeue_style('classic-theme-styles');
});
function s101_copy(): array { static $copy; return $copy??=json_decode(file_get_contents(__DIR__.'/reference/copy.json'),true); }
function s101_markup(string $html): string
{
    $html=str_replace(['/assets/','/api/send-request.php'],[get_stylesheet_directory_uri().'/assets/',admin_url('admin-post.php?action=s101_request')],$html);
    return preg_replace_callback('~(href|action)="(/[^"#]*)"~',static fn($m)=>$m[1].'="'.esc_url(home_url($m[2])).'"',$html);
}
function s101_reference(string $name): void { echo s101_markup(file_get_contents(__DIR__.'/reference/'.$name.'.html')); }
function s101_device_name(array $device): string
{
    $brand=preg_split('/\s+/',$device['brand']); $model=preg_split('/\s+/',$device['name']);
    if (mb_strtolower(end($brand))===mb_strtolower($model[0])) { array_shift($model); }
    return trim($device['brand'].' '.implode(' ',$model));
}
function s101_state(array $extra=[]): void
{
    $state=array_merge(['page'=>is_front_page()?'home':'b2b','root'=>untrailingslashit(home_url()),'formEndpoint'=>admin_url('admin-post.php?action=s101_request'),'statsUrl'=>get_stylesheet_directory_uri().'/data/repair-stats.json'],$extra);
    echo '<script id="page-state" type="application/json">'.wp_json_encode($state,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE).'</script>';
}
function s101_catalog_seo_title(): string
{
    if (\Service101\Routes::$onsite) { return 'Выездной ремонт техники'; }
    $category=(string)get_query_var('s101_category');
    if ($category!=='') { return s101_copy()['categories'][$category]['repairTitle']??\Service101\Routes::$device['category_title']; }
    return 'Ремонт '.s101_device_name(\Service101\Routes::$device);
}
add_filter('pre_get_document_title',static function($title){
    if (class_exists('Service101\\Routes') && \Service101\Routes::$device) { return s101_catalog_seo_title().' в Комсомольске-на-Амуре | Сервис 101'; }
    if (is_front_page()) { return 'Сервис 101 - ремонт техники в Комсомольске-на-Амуре'; }
    return $title;
});
add_action('wp_head',static function(){
    $device=class_exists('Service101\\Routes')?\Service101\Routes::$device:null;
    $description=$device ? s101_catalog_seo_title().' в Комсомольске-на-Амуре: услуги, цены, сроки и запись в Сервис 101.' : 'Сервис 101: ремонт телефонов, ноутбуков, компьютеров, приставок, видеокарт и геймпадов в Комсомольске-на-Амуре.';
    echo '<meta name="description" content="'.esc_attr($description).'">';
    echo '<link rel="canonical" href="'.esc_url(home_url((string)wp_parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH))).'">';
    foreach ([32,192] as $size) { echo '<link rel="icon" type="image/png" sizes="'.$size.'x'.$size.'" href="'.esc_url(get_stylesheet_directory_uri().'/assets/branding/favicon-'.$size.'.png').'">'; }
});
