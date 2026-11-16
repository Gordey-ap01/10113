<?php
declare(strict_types=1);
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Labor.php';
require __DIR__ . '/../src/Suppliers.php';
use Poisk\Http; use Poisk\Labor; use Poisk\Suppliers;
$checks = 0;
function check(bool $pass, string $label): void { global $checks; $checks++; if (!$pass) { fwrite(STDERR,"FAIL: $label\n"); exit(1); } }
foreach ([['iPhone 11','display','OLED',4000],['iPhone 12','display','TFT / IPS',5000],['iPhone 11','charging',null,3000],['iPhone 12','charging',null,4000],['iPhone 16','back',null,10000],['iPhone 17','back',null,null],['iPhone SE','display','LCD',null],['Samsung A54','display','AMOLED',4000],['Redmi Note 12','display','TFT / IPS',2500],['Pixel','display',null,null],['iPhone 16','battery',null,4000],['Pixel','battery',null,2000],['Pixel','back',null,null]] as [$model,$part,$tech,$expected]) check(Labor::price($model,$part,$tech)===$expected,"labor: $model $part");
foreach (['https://taggsm.ru/a','https://khabarovsk.moba.ru/catalog/','https://parts.dvsota.ru/catalog/'] as $u) check(Http::allowed($u),'allowed supplier');
foreach (['http://taggsm.ru/a','https://taggsm.ru.evil.test/','https://user@taggsm.ru/','https://127.0.0.1/','file:///etc/passwd','https://taggsm.ru:443/'] as $u) check(!Http::allowed($u),'SSRF denied');
check(Suppliers::matches('iPhone 12','Дисплей для iPhone 12/12 Pro + тачскрин'),'compatible combined model');
check(!Suppliers::matches('iPhone 12','Дисплей для iPhone 12 mini'),'exclude mini');
check(!Suppliers::matches('iPhone 12','Дисплей для iPhone 13'),'exclude other generation');
check(!Suppliers::matches('iPhone 12 Pro','Дисплей для iPhone 12 Pro Max'),'exclude pro max');
check(Suppliers::matches('iPhone 12 Pro','Дисплей для iPhone 12/12 Pro'),'combined model includes Pro');
check(Suppliers::enrich(['name'=>'Наклейка на дисплей iPhone 12'])['part']==='other','display adhesive is not a display repair');
check(Suppliers::enrich(['name'=>'Шлейф аккумулятора iPhone 12'])['part']==='other','battery flex is not a battery repair');
check(Suppliers::enrich(['name'=>'Аккумулятор неоригинальный'])['origin']==='copy','non-original does not become original');
check(Suppliers::enrich(['name'=>'Дисплей снятый, заменено только стекло'])['condition']==='restored','glass replacement is restored even when pulled');
$e=Suppliers::enrich(['name'=>'Дисплей iPhone 12 copy OLED REF','url'=>'https://taggsm.ru/','price'=>100]);
check($e['technology']==='OLED'&&$e['origin']==='copy'&&$e['condition']==='restored','independent quality fields');
check($e['quantity']===null&&$e['delivery_cost']===null,'unknown quantity and delivery stay unknown');
$e=Suppliers::enrich(['name'=>'Дисплей OR100 снятый + ALS','url'=>'https://taggsm.ru/','price'=>100]);
check($e['origin']==='original_claimed'&&$e['condition']==='pulled','supplier originality claim');
$fixture = new class implements \Poisk\HttpTransport {
    public string $source = '';
    public function get(string $url): string { return file_get_contents(__DIR__ . '/fixtures/' . $this->source . '.html'); }
};
$sources = new Suppliers($fixture);
foreach (['taggsm'=>'iPhone 12 mini','moba'=>'iPhone 12','dvsota'=>'Дисплей','greenspark'=>'iPhone 12'] as $source=>$query) {
    $fixture->source=$source;$result=$sources->search($source,$query,'display');
    check(count($result['items'])===2,'actual supplier HTML cards: '.$source);
    check($result['items'][0]['price']>0,'actual supplier price: '.$source);
    check(str_contains($result['items'][0]['url'],'/catalog/')||str_contains($result['items'][0]['url'],'product_id='),'actual product URL: '.$source);
    if($source==='moba')check(str_starts_with($result['items'][0]['name'],'Дисплей'),'MOBA hidden template tag is excluded');
    if($source==='greenspark')check($result['items'][0]['stock']==='out'&&$result['items'][1]['stock']==='in','Nuxt references preserve stock per product');
}
$fixture->source='moba';$result=$sources->search('moba','LCD-PMI120-CP-B-HOD','display');
check(count($result['items'])===1,'article search matches supplier SKU');
check(Labor::price($result['items'][0]['name'],'display',$result['items'][0]['technology'])===5000,'article result uses actual iPhone repair rate');
echo "PASS: $checks checks\n";
