<?php
declare(strict_types=1);
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Suppliers.php';
$dir = __DIR__ . '/../var/probe';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$sources = new \Poisk\Suppliers(new \Poisk\Http($dir));
foreach (array_keys(\Poisk\Suppliers::LABELS) as $source) {
    if (isset($argv[3]) && $argv[3] !== $source) continue;
    $t = microtime(true);
    try {
        $r = $sources->search($source, $argv[1] ?? 'iPhone 12', $argv[2] ?? 'display', (int) ($argv[4] ?? 1));
        echo json_encode(['source'=>$source,'seconds'=>round(microtime(true)-$t,2),'count'=>count($r['items']),'scanned'=>$r['scanned'],'sample'=>$r['items'][0]??null,'next_page'=>$r['next_page'],'url'=>$r['source_url']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $e) { echo json_encode(['source'=>$source,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE) . "\n"; }
}
