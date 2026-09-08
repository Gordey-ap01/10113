<?php
/**
 * Plugin Name: Сервис 101 — каталог и Excel
 * Description: Устройства, услуги, цены, проверяемый импорт и экспорт Excel.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 */
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;
const PLUGIN_DIR = __DIR__;
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/workbook.php';
require_once __DIR__ . '/includes/import.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/routes.php';
register_activation_hook(__FILE__, [Catalog::class, 'activate']);
add_action('init', [Routes::class, 'register']);
add_action('admin_menu', [Admin::class, 'menu']);
add_action('admin_post_s101_catalog', [Admin::class, 'action']);
add_filter('post_type_link', [Routes::class, 'permalink'], 10, 2);
add_action('template_redirect', [Routes::class, 'resolve']);
add_filter('query_vars', static fn(array $vars): array => array_merge($vars, ['s101_path', 's101_category', 's101_onsite']));
