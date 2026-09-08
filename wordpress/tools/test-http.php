<?php
/** Read-only route checks through Beget's actual PHP frontend. */
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
$private=getenv('HOME').'/.service101-stage';
$secrets=json_decode(ltrim(file_get_contents($private.'/secrets.json'),"\xEF\xBB\xBF"),true);
$qa=json_decode(file_get_contents($private.'/browser-qa.json'),true);
$request=static function(string $path,bool $logged) use($secrets,$qa): array {
    $curl=curl_init(set_url_scheme(home_url($path),'http'));
    $headers=['X-S101-QA: '.$qa['secret'],'Cookie: beget=begetok'.($logged?'; '.$qa['cookies']:'')];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>$secrets['gate_user'].':'.$secrets['gate_password'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15]);
    $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $error=curl_error($curl); curl_close($curl);
    if ($body===false) { throw new RuntimeException($error); }
    return [$status,$body];
};
$devices=\Service101\Catalog::devices(true); $paths=[];
foreach ($devices as $device) { $paths[$device['path']]=true; $paths['/remont/'.$device['category_slug'].'/']=true; }
$paths['/remont/vyezdnoj-remont/']=true;
foreach (array_keys($paths) as $path) {
    [$status,$body]=$request($path,true);
    if ($status!==200 || !str_contains($body,'class="price-row"') || !str_contains($body,'"serverRendered":true')) { throw new RuntimeException('Admin route failed: '.$path.' HTTP '.$status); }
    [$status]=$request($path,false);
    if ($status!==404) { throw new RuntimeException('Draft leaked to visitors: '.$path.' HTTP '.$status); }
}
foreach (['/','/b2b/'] as $path) { [$status,$body]=$request($path,false); if ($status!==200 || !str_contains($body,'<form')) { throw new RuntimeException('Page/form failed: '.$path.' HTTP '.$status); } }
$first=reset($devices);
foreach ([$first['path'].'index.html','/b2b/index.html'] as $path) { [$status]=$request($path,true); if ($status!==301) { throw new RuntimeException('Legacy URL must redirect: '.$path); } }
echo 'PASS '.count($paths)." catalog routes: admin HTML contains prices, anonymous drafts return 404.\nPASS home and B2B forms; legacy URL redirects.\n";
