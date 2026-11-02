<?php
/** Gordey_ap01: counts and public paths only, never customer records. */
global $wpdb;
$prefix = $wpdb->prefix;
$counts = [];
foreach (['s101_devices', 's101_prices', 's101_requests', 's101_services', 'posts', 'users'] as $table) {
    $counts[$table] = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $prefix . $table . '`');
}
$paths = $wpdb->get_col('SELECT path FROM `' . $prefix . 's101_devices` ORDER BY path');
foreach (['telefony', 'noutbuki', 'pristavki', 'kompyutery', 'videokarty', 'gejmpady', 'vyezdnoj-remont'] as $category) {
    $paths[] = '/remont/' . $category . '/';
}
echo wp_json_encode([
    'author' => 'Gordey_ap01',
    'wordpress_version' => $GLOBALS['wp_version'],
    'active_theme' => get_stylesheet(),
    'home' => home_url(),
    'counts' => $counts,
    'catalog_paths' => array_values(array_unique($paths)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
