<?php
/** Malformed upload fixtures; reads only, never applies a catalog change. */
use Service101\Workbook;
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) { throw new RuntimeException('Staging administrator only.'); }
$source=getenv('HOME').'/.service101-stage/catalog.xlsx';
$fixtures=[
    'formula'=>static function(ZipArchive $zip): void {
        $document=new DOMDocument(); $document->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'),LIBXML_NONET);
        $cell=(new DOMXPath($document))->query('//*[local-name()="c" and @r="B8"]')->item(0);
        if (!$cell) { throw new RuntimeException('Fixture cell not found.'); }
        while ($cell->firstChild) { $cell->removeChild($cell->firstChild); }
        $cell->removeAttribute('t');
        foreach (['f'=>'1+1','v'=>'2'] as $tag=>$value) { $cell->appendChild($document->createElementNS($cell->namespaceURI,($cell->prefix?$cell->prefix.':':'').$tag,$value)); }
        $zip->addFromString('xl/worksheets/sheet1.xml',$document->saveXML());
    },
    'macro'=>static fn(ZipArchive $zip)=>$zip->addFromString('xl/vbaProject.bin','test fixture'),
    'external link'=>static fn(ZipArchive $zip)=>$zip->addFromString('xl/externalLinks/externalLink1.xml','<externalLink/>'),
    'embedded file'=>static fn(ZipArchive $zip)=>$zip->addFromString('xl/embeddings/object1.bin','test fixture'),
];
foreach ($fixtures as $name=>$mutate) {
    $path=wp_tempnam('s101-upload-fixture');
    try {
        copy($source,$path); $zip=new ZipArchive();
        if ($zip->open($path)!==true) { throw new RuntimeException('Cannot prepare test fixture.'); }
        try { $mutate($zip); } finally { $zip->close(); }
        $error=''; try { Workbook::read($path); } catch (Throwable $exception) { $error=$exception->getMessage(); }
        if ($error==='' || ($name==='formula' && (!str_contains($error,'формула') || !str_contains($error,'Лист «Устройства», строка 8:')))) { throw new RuntimeException('Unsafe workbook was not rejected correctly: '.$name); }
        echo 'PASS rejected '.$name." workbook.\n";
    } finally { wp_delete_file($path); }
}
