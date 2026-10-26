<?php
/**
 * Plugin Name: Gordey_ap01 — базовая защита
 * Description: Отключает встроенное редактирование PHP тем и плагинов.
 * Version: 1.0.0
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
    define( 'DISALLOW_FILE_EDIT', true );
}
