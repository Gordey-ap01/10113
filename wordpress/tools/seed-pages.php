<?php
if (wp_get_environment_type()!=='staging') { throw new RuntimeException('Staging only.'); }
if (!current_user_can('unfiltered_html')) { throw new RuntimeException('Run as an administrator to preserve the existing forms and HTML.'); }
$theme=get_theme_root().'/service101';
foreach (['home'=>'Сервис 101','b2b'=>'Ремонт техники для бизнеса'] as $slug=>$title) {
    $existing=get_page_by_path($slug);
    if ($existing) {
        $id=$existing->ID;
        if (!get_post_meta($id,'_s101_seeded',true)) { wp_update_post(wp_slash(['ID'=>$id,'post_content'=>file_get_contents($theme.'/reference/'.$slug.'.html')])); }
    }
    else {
        $id=wp_insert_post(wp_slash(['post_type'=>'page','post_status'=>'publish','post_name'=>$slug,'post_title'=>$title,'post_content'=>file_get_contents($theme.'/reference/'.$slug.'.html')]),true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    }
    update_post_meta($id,'_s101_seeded',true);
    if ($slug==='home') { update_option('page_on_front',$id); update_option('show_on_front','page'); }
}
update_option('timezone_string','Asia/Vladivostok'); update_option('blog_public',0); update_option('blogdescription','Ремонт цифровой техники в Комсомольске-на-Амуре');
switch_theme('service101'); flush_rewrite_rules(false);
echo "Pages seeded; Service 101 theme activated.\n";
