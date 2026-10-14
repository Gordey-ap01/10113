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
$category_position=strpos($listing,'>Категории</b>');
$brand_position=strpos($listing,'>Бренды</b>');
$device_position=strpos($listing,'>Устройства</b>');
s101_delete_ui_check($category_position!==false && $category_position<$brand_position && $brand_position<$device_position,'catalog workflow is category, brand, then device');

$categories=s101_render_admin(['page'=>'s101-categories']);
s101_delete_ui_check(str_contains($categories,'value="delete_category"'),'category list renders cascade delete actions');

$brands=s101_render_admin(['page'=>'s101-brands']);
s101_delete_ui_check(str_contains($brands,'value="delete_brand"'),'brand list renders cascade delete actions');

$devices=Catalog::devices(true); $code=(string)array_key_first($devices);
if ($code!=='') {
    $editor=s101_render_admin(['page'=>'s101-catalog','edit'=>$code]);
    s101_delete_ui_check(!preg_match('/id="s101-device-category"[^>]*disabled/',$editor),'existing device category remains editable');
    s101_delete_ui_check(!preg_match('/id="s101-device-brand"[^>]*disabled/',$editor),'existing device brand remains editable');
    s101_delete_ui_check(str_contains($editor,'class="button button-small s101-remove-service"'),'existing service rows have explicit delete controls');
    s101_delete_ui_check((bool)preg_match('/data-delete-flag name="prices\[\d+\]\[_delete\]" value="0"/',$editor),'service rows are not marked for deletion by default');
    s101_delete_ui_check((bool)preg_match('/class="button button-small s101-remove-service" type="button"/',$editor),'delete-row control cannot submit the form by itself');
    s101_delete_ui_check(str_contains($editor,'data-price-amount="total"') && str_contains($editor,'type="number" min="0" step="0.01"'),'price inputs accept numeric zero');
    s101_delete_ui_check(str_contains($editor,'type.value="Фиксированная"'),'entering an amount switches a nonnumeric price type');
}
