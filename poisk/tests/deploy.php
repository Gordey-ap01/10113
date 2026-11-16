<?php
declare(strict_types=1);
require __DIR__ . '/../ops/deploy.php';
use function Poisk\Deploy\authorized;
use function Poisk\Deploy\unpack;
$checks = 0;
function verify(bool $ok, string $label): void { global $checks; $checks++; if (!$ok) { fwrite(STDERR, 'FAIL: ' . $label . "\n"); exit(1); } }
$key = str_repeat('a',64); $release = str_repeat('b',40); $body = 'release'; $now = 1800000000;
$sign = hash_hmac('sha256', "$now\n$release\n" . hash('sha256',$body), $key);
verify(authorized($key,(string)$now,$release,$sign,$body,$now),'valid deployment signature');
verify(!authorized($key,(string)$now,$release,$sign,$body.'!', $now),'modified archive rejected');
verify(!authorized($key,(string)$now,str_repeat('c',40),$sign,$body,$now),'modified commit rejected');
verify(!authorized($key,(string)$now,$release,$sign,$body,$now+301),'expired request rejected');
verify(!authorized($key,(string)$now,$release,$sign,$body,$now-301),'future request rejected');
verify(!authorized($key,(string)$now,'../../101service.ru',$sign,$body,$now),'path cannot become release ID');
verify(!authorized('wrong',(string)$now,$release,$sign,$body,$now),'invalid deployment key rejected');
$temp = tempnam(sys_get_temp_dir(),'poisk-');
function archive(string $temp, string $release, ?string $unsafe = null, bool $corrupt = false): void {
    $zip=new ZipArchive(); $zip->open($temp,ZipArchive::CREATE|ZipArchive::OVERWRITE); $manifest=['release'=>$release,'files'=>[]];
    foreach (Poisk\Deploy\FILES as $name) { $blob='test '.$name; $zip->addFromString($name,$blob); $manifest['files'][$name]=hash('sha256',$blob); }
    if($corrupt)$manifest['files']['src/Http.php']=str_repeat('0',64);
    if($unsafe!==null)$zip->addFromString($unsafe,'forbidden');
    $zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR)); $zip->close();
}
function rejected(string $temp, string $release): bool { try { unpack($temp,$release);return false; } catch(Throwable) { return true; } }
try {
    archive($temp,$release); verify(count(unpack($temp,$release))===6,'valid isolated package accepted');
    verify(rejected($temp,str_repeat('c',40)),'archive commit mismatch rejected');
    archive($temp,$release,null,true);verify(rejected($temp,$release),'tampered file checksum rejected');
    foreach(['../config.php','public/index.php','../../101service.ru/public_html/index.html','var/poisk.sqlite'] as $name){archive($temp,$release,$name);verify(rejected($temp,$release),'extra sensitive path rejected: '.$name);}
    archive($temp,$release);$zip=new ZipArchive();$zip->open($temp);$zip->setExternalAttributesName('src/Http.php',ZipArchive::OPSYS_UNIX,0120777<<16);$zip->close();verify(rejected($temp,$release),'archive symlink rejected');
} finally { unlink($temp); }
echo "PASS: $checks deployment security checks\n";
