<?php
/** Staging-only editor contract checks; previews and rendering do not mutate catalog data. */
use Service101\Admin;
use Service101\Catalog;
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
function ux_check(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } echo 'PASS '.$message."\n"; }
$devices=Catalog::devices(true); $prices=Catalog::prices(); $revision=Catalog::revision();
$device=reset($devices); $rows=Catalog::prices($device['code']); $first=reset($rows);
$plan=Admin::device_plan($device,[], $revision);
ux_check(!$plan['errors'] && !$plan['prices'] && !$plan['counts']['deleted'],'omitted rows do not become deletions');
$plan=Admin::device_plan($device,[['name'=>'','total_amount'=>'5','_delete'=>'1']],$revision);
ux_check(!$plan['errors'] && !$plan['prices'],'discarding a partially entered new row does not create a service');
$row=$first; $row['badge']=''; $row['description']='';
$plan=Admin::device_plan($device,[$row],$revision); $change=reset($plan['prices']);
ux_check(!$plan['errors'] && $change['after']['badge']==='' && $change['after']['description']==='','clearing optional admin fields actually clears them');
$row=['name'=>'QA generated code','total_type'=>'Фиксированная','total_amount'=>'0','work_type'=>'По запросу','order'=>'0'];
$plan=Admin::device_plan($device,[$row],$revision); $change=reset($plan['prices']);
ux_check(!$plan['errors'] && preg_match('/^S[A-F0-9]{32}$/D',$change['after']['service_code'])===1,'new service receives a system-generated stable code');
ux_check($change['after']['total_amount']==='0.00' && $change['after']['order']===0,'zero amount and zero order survive editor validation');
$row=$first; $row['name']=''; $blocked=false;
try { Admin::device_plan($device,[$row],$revision); } catch (InvalidArgumentException $error) { $blocked=true; }
ux_check($blocked,'clearing an existing service name cannot silently delete or preserve it');
$delete=['service_code'=>$first['service_code'],'_delete'=>'1'];
$plan=Admin::device_plan($device,[$delete],$revision);
ux_check(!$plan['errors'] && $plan['counts']['deleted']===1,'only explicit deletion flag produces a delete preview');
ux_check(Catalog::prices()===$prices && Catalog::revision()===$revision,'preview does not change catalog rows or revision');
$token=wp_generate_uuid4();
set_transient('s101_form_'.get_current_user_id().'_'.$token,['term'=>['name'=>'QA unsaved brand','slug'=>'qa-unsaved','category_slugs'=>[]],'message'=>'Выберите категорию','revision'=>$revision],60);
try {
    $_GET=['page'=>'s101-brands','form_draft'=>$token]; ob_start(); Admin::page(); $html=ob_get_clean();
    ux_check(str_contains($html,'value="QA unsaved brand"') && str_contains($html,'value="qa-unsaved"'),'fallback error page restores entered brand values');
} finally { delete_transient('s101_form_'.get_current_user_id().'_'.$token); }
ux_check(Catalog::devices(true)===$devices && Catalog::prices()===$prices,'QA leaves catalog data unchanged');
