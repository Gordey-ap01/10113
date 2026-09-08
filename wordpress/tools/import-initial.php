<?php
/** Run with wp eval-file, --user=<catalog administrator>, on a staging instance only. */
use Service101\Catalog;
use Service101\Workbook;
use Service101\Import;
if (wp_get_environment_type()!=='staging') { throw new RuntimeException('Initial migration is restricted to staging.'); }
$input=Workbook::read(getenv('HOME').'/.service101-stage/catalog.xlsx');
$plan=Import::preview($input);
echo wp_json_encode(['devices'=>count($input['devices']),'prices'=>count($input['prices']),'counts'=>$plan['counts'],'errors'=>array_slice($plan['errors'],0,20)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
if ($plan['errors']) { throw new RuntimeException('Initial catalog validation failed.'); }
$id=Import::save_plan($plan); $applied=Import::apply($id);
echo wp_json_encode(['batch'=>$id,'devices'=>count(Catalog::devices(true)),'public_devices'=>count(Catalog::devices(false)),'prices'=>count(Catalog::prices()),'warnings'=>$applied['warnings']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
