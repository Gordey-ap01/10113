<?php
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;

final class Routes
{
    public static ?array $device=null;
    public static string $category='';
    public static bool $onsite=false;

    public static function register(): void
    {
        register_post_type('s101_device',['labels'=>['name'=>'Устройства','singular_name'=>'Устройство'],'public'=>true,'show_ui'=>false,'show_in_rest'=>false,'exclude_from_search'=>false,'supports'=>['title','editor','thumbnail'],'rewrite'=>false,'has_archive'=>false]);
        register_taxonomy('s101_category','s101_device',['label'=>'Категории устройств','public'=>false,'show_ui'=>false,'rewrite'=>false]);
        register_taxonomy('s101_brand','s101_device',['label'=>'Бренды','public'=>false,'show_ui'=>false,'rewrite'=>false]);
        add_rewrite_rule('^remont/vyezdnoj-remont(?:/index\.html)?/?$','index.php?s101_onsite=1','top');
        add_rewrite_rule('^remont/([a-z0-9-]+)/([a-z0-9-]+)/([a-z0-9-]+)(?:/index\.html)?/?$','index.php?s101_path=$matches[1]/$matches[2]/$matches[3]','top');
        add_rewrite_rule('^remont/([a-z0-9-]+)(?:/index\.html)?/?$','index.php?s101_category=$matches[1]','top');
        add_rewrite_rule('^b2b/index\.html$','index.php?pagename=b2b','top');
    }
    public static function permalink(string $link, \WP_Post $post): string
    {
        if ($post->post_type!=='s101_device') { return $link; }
        global $wpdb;
        $path=$wpdb->get_var($wpdb->prepare('SELECT path FROM '.Catalog::table('devices').' WHERE post_id=%d',$post->ID));
        return $path ? home_url($path) : $link;
    }
    public static function can_preview(): bool { return is_user_logged_in() && current_user_can('manage_s101_catalog'); }
    public static function resolve(): void
    {
        $path=(string)get_query_var('s101_path'); $category=(string)get_query_var('s101_category'); $onsite=(bool)get_query_var('s101_onsite');
        if (!$path && !$category && !$onsite) { return; }
        $devices=Catalog::devices(self::can_preview());
        foreach ($devices as $device) {
            $match=$path ? $device['path']==='/remont/'.$path.'/' : ($onsite ? in_array($device['category_slug'],['noutbuki','kompyutery'],true) : $device['category_slug']===$category);
            if ($match) { self::$device=$device; break; }
        }
        if (!self::$device) { global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); return; }
        self::$category=self::$device['category_slug']; self::$onsite=$onsite;
        global $wp_query;
        $wp_query->is_404=false; $wp_query->is_home=false; $wp_query->is_singular=true;
        $wp_query->queried_object=get_post(self::$device['post_id']); $wp_query->queried_object_id=self::$device['post_id'];
        status_header(200);
        if (self::$device['publication']!=='Опубликовать') { nocache_headers(); header('X-Robots-Tag: noindex, nofollow'); }
        $canonical=home_url($path ? self::$device['path'] : ($onsite?'/remont/vyezdnoj-remont/':'/remont/'.$category.'/'));
        if (wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!==wp_parse_url($canonical,PHP_URL_PATH)) { wp_safe_redirect($canonical,301); exit; }
        remove_action('template_redirect','redirect_canonical');
        add_filter('template_include',static fn()=>get_stylesheet_directory().'/catalog.php');
    }
}
