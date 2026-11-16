<?php
declare(strict_types=1);
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Suppliers.php';
require __DIR__ . '/../src/State.php';
use Poisk\Http; use Poisk\Suppliers;
$checks = 0;
function check(bool $pass, string $label): void { global $checks; $checks++; if (!$pass) { fwrite(STDERR,"FAIL: $label\n"); exit(1); } }
foreach (['https://taggsm.ru/a','https://khabarovsk.moba.ru/catalog/','https://parts.dvsota.ru/catalog/'] as $u) check(Http::allowed($u),'allowed supplier');
foreach (['http://taggsm.ru/a','https://taggsm.ru.evil.test/','https://user@taggsm.ru/','https://127.0.0.1/','file:///etc/passwd','https://taggsm.ru:443/'] as $u) check(!Http::allowed($u),'SSRF denied');
check(Suppliers::matches('iPhone 12','Дисплей для iPhone 12/12 Pro + тачскрин'),'compatible combined model');
check(!Suppliers::matches('iPhone 12','Дисплей для iPhone 12 mini'),'exclude mini');
check(!Suppliers::matches('iPhone 12','Дисплей для iPhone 13'),'exclude other generation');
check(!Suppliers::matches('iPhone 12 Pro','Дисплей для iPhone 12 Pro Max'),'exclude pro max');
check(Suppliers::matches('iPhone 12 Pro','Дисплей для iPhone 12/12 Pro'),'combined model includes Pro');
check(Suppliers::matches('динамик iPhone 12','Динамик разговорный для iPhone 12'),'arbitrary part name with an exact iPhone model');
check(!Suppliers::matches('динамик iPhone 12','Дисплей для iPhone 12'),'model match does not discard requested part name');
check(Suppliers::enrich(['name'=>'Наклейка на дисплей iPhone 12'])['part']==='other','display adhesive is not a display repair');
check(Suppliers::enrich(['name'=>'Шлейф аккумулятора iPhone 12'])['part']==='other','battery flex is not a battery repair');
check(Suppliers::enrich(['name'=>'Аккумулятор неоригинальный'])['origin']==='copy','non-original does not become original');
check(Suppliers::enrich(['name'=>'Дисплей снятый, заменено только стекло'])['condition']==='restored','glass replacement is restored even when pulled');
$e=Suppliers::enrich(['name'=>'Дисплей iPhone 12 copy OLED REF','url'=>'https://taggsm.ru/','price'=>100]);
check($e['technology']==='OLED'&&$e['origin']==='copy'&&$e['condition']==='restored','independent quality fields');
check($e['quantity']===null&&$e['delivery_cost']===null,'unknown quantity and delivery stay unknown');
check(Suppliers::enrich(['name'=>'Деталь','warehouse'=>'GreenSpark Комсомольск-на-Амуре · Ленина, 4'])['is_local'],'confirmed local warehouse');
check(!Suppliers::enrich(['name'=>'Деталь для Комсомольска-на-Амуре','warehouse'=>'Москва'])['is_local'],'locality comes from warehouse rather than product name');
$e=Suppliers::enrich(['name'=>'Дисплей OR100 снятый + ALS','url'=>'https://taggsm.ru/','price'=>100]);
check($e['origin']==='original_claimed'&&$e['condition']==='pulled','supplier originality claim');
$fixture = new class implements \Poisk\HttpTransport {
    public string $source = '';
    public string $lastUrl = '';
    public ?string $override = null;
    public function get(string $url): string { $this->lastUrl=$url;return $this->override ?? file_get_contents(__DIR__ . '/fixtures/' . $this->source . '.html'); }
};
$sources = new Suppliers($fixture);
foreach (['taggsm'=>'iPhone 12 mini','moba'=>'iPhone 12','dvsota'=>'Дисплей','greenspark'=>'iPhone 12'] as $source=>$query) {
    $fixture->source=$source;$result=$sources->search($source,$query,'display');
    check(count($result['items'])===2,'actual supplier HTML cards: '.$source);
    check($result['items'][0]['price']>0,'actual supplier price: '.$source);
    check(str_contains($result['items'][0]['url'],'/catalog/')||str_contains($result['items'][0]['url'],'product_id='),'actual product URL: '.$source);
    if($source==='moba')check(str_starts_with($result['items'][0]['name'],'Дисплей'),'MOBA hidden template tag is excluded');
    if($source==='greenspark') {
        check($result['items'][0]['stock']==='out'&&$result['items'][1]['stock']==='in','Nuxt references preserve stock per product');
        check($result['items'][0]['is_local']&&str_contains($result['items'][0]['warehouse'],'Ленина, 4'),'out-of-stock record retains its confirmed local warehouse');
        check($result['items'][1]['price']===4360.0&&$result['items'][1]['price_type']==='Розница.','price belongs to the selected local shop');
        parse_str(parse_url($fixture->lastUrl,PHP_URL_QUERY),$greenParams);
        check($greenParams['set_city']==='249529','GreenSpark explicitly requests the Komso shop');
    }
}
$fixture->source='greenspark';
$fixture->override=str_replace(['249529','Комсомольск-на-Амуре'],['16366','Москва'],file_get_contents(__DIR__.'/fixtures/greenspark.html'));
$wrongShopRejected=false;
try {$sources->search('greenspark','iPhone 12','display');}catch(RuntimeException){$wrongShopRejected=true;}
check($wrongShopRejected,'Moscow response cannot be presented as local stock');
$fixture->override=null;
$fixture->source='moba';$result=$sources->search('moba','LCD-PMI120-CP-B-HOD','display');
check(count($result['items'])===1,'article search matches supplier SKU');
parse_str(parse_url($fixture->lastUrl,PHP_URL_QUERY),$params);
check($params['q']==='LCD-PMI120-CP-B-HOD','article lookup does not append model search keywords');
$sources->search('moba','iPhone 12','all');
parse_str(parse_url($fixture->lastUrl,PHP_URL_QUERY),$params);
check($params['q']==='iPhone 12','all-parts search does not force a part category');

$historyRoot = sys_get_temp_dir() . '/poisk-history-' . bin2hex(random_bytes(8));
$state = new \Poisk\State($historyRoot, []);
try {
    $state->rememberSearch(1,'Динамик iPhone 12','all');
    $state->rememberSearch(1,'Камера Samsung A54','all');
    $state->rememberSearch(1,'динамик   IPHONE 12','all');
    $state->rememberSearch(2,'Микрофон','all');
    $history = $state->searchHistory(1);
    check(count($history)===2 && $history[0]['uses']===2,'repeat queries merge across letter case and spaces and rank first');
    check($state->searchHistory(2)[0]['q']==='Микрофон' && count($state->searchHistory(2))===1,'history belongs to the signed-in master');
    $state->rememberSearch(1,'динамик   IPHONE 12','display');
    check(count($state->searchHistory(1))===3,'history preserves the selected category for repeat searches');
    for ($i=0;$i<55;$i++) $state->rememberSearch(1,'Деталь '.$i,'all');
    check(count($state->searchHistory(1))===50,'history retention is bounded per master');
    check(count($state->searchHistory(2))===1,'trimming one history preserves another master history');
} finally {
    unset($state);
    foreach (['/var/poisk.sqlite-wal','/var/poisk.sqlite-shm','/var/poisk.sqlite'] as $file) if (is_file($historyRoot.$file)) unlink($historyRoot.$file);
    foreach (['/var/sessions','/var/http','/var',''] as $dir) rmdir($historyRoot.$dir);
}
echo "PASS: $checks checks\n";
