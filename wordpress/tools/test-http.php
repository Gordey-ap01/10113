<?php
/** Read-only checks of the public staging preview over its real HTTPS host. */
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
if (!get_option('s101_public_catalog_preview',false)) { throw new RuntimeException('Enable the public staging preview before this check.'); }
$request=static function(string $path): array {
    $curl=curl_init(set_url_scheme(home_url($path),'https'));
    $headers=[];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Cookie: beget=begetok'],CURLOPT_TIMEOUT=>15,
        CURLOPT_HEADERFUNCTION=>static function($curl,$line) use(&$headers){ $headers[]=strtolower(trim($line)); return strlen($line); }]);
    $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $error=curl_error($curl); curl_close($curl);
    if ($body===false) { throw new RuntimeException($error); }
    if (preg_grep('/^www-authenticate:/',$headers)) { throw new RuntimeException('Unexpected password gate: '.$path); }
    if (!preg_grep('/^x-robots-tag:.*noindex/',$headers)) { throw new RuntimeException('Missing staging noindex: '.$path); }
    return [$status,$body,$headers];
};
$devices=\Service101\Catalog::devices(true); $paths=[];
foreach ($devices as $device) { $paths[$device['path']]=true; $paths['/remont/'.$device['category_slug'].'/']=true; }
$paths['/remont/vyezdnoj-remont/']=true;
foreach (array_keys($paths) as $path) {
    [$status,$body]=$request($path);
    if ($status!==200 || !str_contains($body,'class="price-row"') || !str_contains($body,'"serverRendered":true')) { throw new RuntimeException('Public catalog route failed: '.$path.' HTTP '.$status); }
}
foreach (['/','/b2b/'] as $path) { [$status,$body]=$request($path); if ($status!==200 || !str_contains($body,'<form')) { throw new RuntimeException('Page/form failed: '.$path.' HTTP '.$status); } }
$first=reset($devices);
foreach ([$first['path'].'index.html','/b2b/index.html'] as $path) { [$status]=$request($path); if ($status!==301) { throw new RuntimeException('Legacy URL must redirect: '.$path); } }
[$status,,$headers]=$request('/wp-admin/admin.php?page=s101-catalog');
if ($status!==302 || !preg_grep('/^location:.*wp-login\.php/',$headers)) { throw new RuntimeException('Anonymous admin access must require WordPress login.'); }
foreach (['/wp-config.php','/wp-content/debug.log'] as $path) {
    [$status]=$request($path);
    if ($status!==403) { throw new RuntimeException('Protected file must remain forbidden: '.$path); }
}
echo 'PASS '.count($paths)." public catalog routes contain prices without authentication; noindex preserved.\nPASS home/B2B forms, legacy redirects, WordPress admin login and private-file protection.\n";
