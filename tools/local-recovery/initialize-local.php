<?php
/** Gordey_ap01: run only against the recovered local database. */
if (wp_get_environment_type() !== 'staging' || home_url() !== 'http://127.0.0.1:8185') {
    throw new RuntimeException('Local recovery environment required.');
}
$login = 'gordey_local';
if (!username_exists($login)) {
    $id = wp_insert_user([
        'user_login' => $login,
        'user_pass' => getenv('LOCAL_ADMIN_PASSWORD'),
        'user_email' => 'local@gordey.invalid',
        'display_name' => 'Gordey_ap01',
        'role' => 'administrator',
    ]);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
}
update_option('blog_public', 0);
update_option('s101_public_catalog_preview', true);
flush_rewrite_rules(false);
echo "Local administrator ready; original catalog preserved.\n";
