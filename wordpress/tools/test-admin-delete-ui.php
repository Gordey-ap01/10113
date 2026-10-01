<?php
/** Run with wp eval-file --user=<administrator> on staging. Read-only admin rendering checks. */
use Service101\Admin;
use Service101\Catalog;

if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
function s101_delete_ui_check(bool $condition,string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message."\n";
}
function s101_render_admin(array $query): string {
    $_GET=$query;
    ob_start(); Admin::page();
    return (string)ob_get_clean();
}

$listing=s101_render_admin(['page'=>'s101-catalog']);
s101_delete_ui_check(str_contains($listing,'value="delete_device"'),'device list renders permanent delete actions');
s101_delete_ui_check(str_contains($listing,'Удалить устройство полностью'),'device deletion requires a confirmation prompt');

$categories=s101_render_admin(['page'=>'s101-categories']);
s101_delete_ui_check(str_contains($categories,'value="delete_category"'),'category list renders cascade delete actions');

$brands=s101_render_admin(['page'=>'s101-brands']);
s101_delete_ui_check(str_contains($brands,'value="delete_brand"'),'brand list renders cascade delete actions');

$devices=Catalog::devices(true); $code=(string)array_key_first($devices);
if ($code!=='') {
    $editor=s101_render_admin(['page'=>'s101-catalog','edit'=>$code]);
    s101_delete_ui_check(!preg_match('/id="s101-device-category"[^>]*disabled/',$editor),'existing device category remains editable');
    s101_delete_ui_check(!preg_match('/id="s101-device-brand"[^>]*disabled/',$editor),'existing device brand remains editable');
}
