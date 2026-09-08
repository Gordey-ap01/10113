import fs from 'node:fs/promises';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';
const repo=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const theme=path.join(repo,'wordpress/wp-content/themes/service101');
await fs.mkdir(path.join(theme,'reference'),{recursive:true});
const read=(name)=>fs.readFile(path.join(repo,name),'utf8');
const normalize=(html)=>html.replace(/(?:\.\.\/|\.\/)+(assets\/)/g,'/$1').replace(/(?:\.\.\/|\.\/)+(b2b\/|remont\/|api\/)/g,'/$1').replace(/(?:\.\.\/|\.\/)+index\.html/g,'/').replace(/(href=")([^"#?]*)index\.html/g,'$1$2');
const home=await read('index.html'); const b2b=await read('b2b/index.html'); const model=await read('remont/telefony/apple/iphone-15/index.html');
for (const [name,html] of [['home',home],['b2b',b2b]]) { await fs.writeFile(path.join(theme,'reference',name+'.html'),normalize(html.match(/<main id="app">([\s\S]*?)<\/main>/)[1])); }
await fs.writeFile(path.join(theme,'reference/header.html'),normalize(home.match(/<header[\s\S]*?<main id="app">/)[0].replace(/<main id="app">$/,'')));
await fs.writeFile(path.join(theme,'reference/footer.html'),normalize(model.match(/<footer[\s\S]*?<script id="page-state"/)[0].replace(/<script id="page-state"$/,'')));
for (const [name,html] of [['home',home],['b2b',b2b]]) { await fs.writeFile(path.join(theme,'reference','footer-'+name+'.html'),normalize(html.match(/<footer[\s\S]*?<script id="page-state"/)[0].replace(/<script id="page-state"$/,''))); }
let script=await read('scripts/app.js');
const context=vm.createContext({document:{getElementById:()=>null,addEventListener:()=>{}},console});
vm.runInContext(script.replace(/\}\)\(\);\s*$/,'globalThis.reference={categoryCopy,deviceInfoCopy,tabIcon,renderContactSection};})();'),context);
const ref=context.reference;
const copy=JSON.parse(JSON.stringify({categories:ref.categoryCopy,info:ref.deviceInfoCopy}));
copy.icons=Object.fromEntries(Object.keys(copy.categories).map(slug=>[slug,ref.tabIcon(slug)]));
await fs.writeFile(path.join(theme,'reference/copy.json'),JSON.stringify(copy,null,2));
await fs.writeFile(path.join(theme,'reference/contact.html'),normalize(ref.renderContactSection()).trimEnd()+'\n');
script=script.replace('const formEndpoint = `${root}/api/send-request.php`;','const formEndpoint = pageState.formEndpoint || `${root}/api/send-request.php`;');
script=script.replace('async function loadCatalog() {',`async function loadCatalog() {
    if (pageState.serverRendered) {
      records = (pageState.services || []).map(normalizeRecord);
      currentServices = records;
      currentDevice = pageState.device;
      bindServiceButtons(); bindModelScroller(); bindHorizontalScroll(); syncBookingBar(); applyRepairStatistics();
      document.documentElement.dataset.catalogSource = 'wordpress';
      return;
    }`);
script=script.replace('fetch(`${root}/data/repair-stats.json`,','fetch(pageState.statsUrl || `${root}/data/repair-stats.json`,');
await fs.mkdir(path.join(theme,'scripts'),{recursive:true});
await fs.writeFile(path.join(theme,'scripts/app.js'),script);
await fs.cp(path.join(repo,'assets'),path.join(theme,'assets'),{recursive:true});
for(const file of ['styles.css','styles-10107.css','styles-10108.css','styles-10110.css']) { await fs.copyFile(path.join(repo,file),path.join(theme,file)); }
await fs.mkdir(path.join(theme,'data'),{recursive:true});
await fs.copyFile(path.join(repo,'data/repair-stats.json'),path.join(theme,'data/repair-stats.json'));
const plugin=path.join(repo,'wordpress/wp-content/plugins/service101-catalog');
let handler=await read('api/send-request.php');
handler=handler.replace('<?php',`<?php\nnamespace Service101\\Forms;\ndefined('ABSPATH') || exit;\n$_POST = wp_unslash($_POST);`);
handler=handler.replace("$config = require __DIR__ . '/mail-config.php';",`$config = require __DIR__ . '/mail-config.php';\n$config['recipient'] = get_option('s101_recipient', $config['recipient']);`);
handler=handler.replace("$ignored = array(","$ignored = array('action', ");
handler=handler.replace('rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)', "rtrim(defined('WP_TEMP_DIR') ? WP_TEMP_DIR : sys_get_temp_dir(), DIRECTORY_SEPARATOR)");
handler=handler.replace("hash('sha256', $ip)", "hash('sha256', request_host() . '|' . $ip)");
handler=handler.replace('$sent = @mail(',`if (wp_get_environment_type() === 'staging') {
    update_option('s101_last_test_request', array('type'=>$formType,'fields'=>array_keys($labels),'at'=>gmdate('c')), false);
    respond(200, true, 'Тестовая заявка принята. Письмо не отправлялось.');
}
$sent = @mail(`);
await fs.writeFile(path.join(plugin,'includes/forms-handler.php'),handler);
await fs.copyFile(path.join(repo,'api/mail-config.php'),path.join(plugin,'includes/mail-config.php'));
console.log('Theme reference, assets and preserved form handler prepared.');
