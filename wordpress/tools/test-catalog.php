<?php
/** Integration checks against the isolated staging database; restores edited prices afterwards. */
use Service101\Catalog;
use Service101\Workbook;
use Service101\Import;
if (wp_get_environment_type()!=='staging') { throw new RuntimeException('Staging only.'); }
function ensure(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } echo "PASS $message\n"; }
$private=getenv('HOME').'/.service101-stage';
$input=Workbook::read($private.'/catalog.xlsx');
$plan=Import::preview($input);
ensure(!$plan['errors'],'initial workbook validates');
ensure($plan['counts']['same']===2058 && $plan['counts']['new']===0,'repeat preview: 2058 unchanged rows');
$media_before=(int)wp_count_posts('attachment')->inherit;
$batch=Import::save_plan($plan); Import::apply($batch); Import::apply($batch);
ensure(count(Catalog::devices(true))===117 && count(Catalog::prices())===1941,'repeated apply does not duplicate devices or prices');
ensure((int)wp_count_posts('attachment')->inherit===$media_before,'repeated import does not duplicate images');
ensure(count(Catalog::devices(false))===0,'all initial devices remain drafts');
$invalid=$input; $invalid['devices'][]=$invalid['devices'][0];
ensure(count(Import::preview($invalid)['errors'])>0,'duplicate device rejected');
$invalid=$input; $invalid['prices'][0]['work_amount']='-10'; $invalid['prices'][0]['work_type']='Фиксированная';
ensure(count(Import::preview($invalid)['errors'])>0,'negative price rejected');
$invalid=$input; $invalid['prices'][0]['device_code']='UNKNOWN';
ensure(count(Import::preview($invalid)['errors'])>0,'unknown device rejected');
$invalid=$input; $invalid['devices'][0]['path']='/remont/bad/path/';
ensure(count(Import::preview($invalid)['errors'])>0,'occupied or mismatching route rejected');
$first=array_values(Catalog::prices())[0]; $changed=$first; $changed['work_type']='От'; $changed['work_amount']='4321.25';
$before=Catalog::prices();
$plan=Import::preview(['devices'=>[],'prices'=>[$changed],'revision'=>Catalog::revision()]);
ensure(!$plan['errors'],'partial price update validates');
$stale=Import::save_plan($plan); $batch=Import::save_plan($plan); Import::apply($batch);
$now=Catalog::prices();
ensure($now[$first['device_code'].'|'.$first['service_code']]['work_amount']==='4321.25','price stored exactly to a kopeck');
ensure(count($now)===1941,'partial import preserves other rows');
$blocked=false; try { Import::apply($stale); } catch (Throwable) { $blocked=true; }
ensure($blocked,'stale preview rejected');
Import::restore($batch); ensure(Catalog::prices()===$before,'rollback restores full price catalog');
$export=$private.'/export-test.xlsx'; Workbook::export($export); $roundtrip=Workbook::read($export);
ensure(count($roundtrip['devices'])===117 && count($roundtrip['prices'])===1941,'Excel export roundtrip preserves all rows');
$plan=Import::preview($roundtrip); ensure(!$plan['errors'],'exported workbook validates for reimport');
echo 'Revision '.Catalog::revision()."\n";
