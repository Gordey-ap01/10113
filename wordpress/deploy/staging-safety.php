<?php
/** Install in wp-content/mu-plugins on the isolated staging installation. */
defined('ABSPATH') || exit;
if (wp_get_environment_type()==='staging') {
    add_filter('pre_wp_mail', '__return_true');
    add_filter('pre_option_blog_public', static fn()=>0);
    add_action('send_headers', static function(){ header('X-Robots-Tag: noindex, nofollow, noarchive'); });
}
