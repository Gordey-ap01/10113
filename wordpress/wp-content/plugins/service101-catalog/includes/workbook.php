<?php
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;

final class Workbook
{
    public const DEVICE_HEADERS = ['Код устройства','Устройство','Категория','Бренд','Публикация','Фото 1: ссылка','Описание устройства','Фото 2: ссылка','Фото 3: ссылка','Код категории','Заголовок категории','Код бренда','Код модели','Существующий URL'];
    public const PRICE_HEADERS = ['Код устройства','Устройство (авто)','Код услуги','Услуга','Тип цены работ','Работа, ₽','Цена с деталью: тип','С деталью, ₽','Срок ремонта','Пометка','Описание работы','Действие','Порядок'];
    public const DEVICE_KEYS = ['code','name','category','brand','publication','image1','description','image2','image3','category_slug','category_title','brand_slug','model_slug','path'];
    public const PRICE_KEYS = ['device_code','device_name','service_code','name','work_type','work_amount','total_type','total_amount','time','badge','description','action','order'];

    private static function loader(): void
    {
        if (!is_file(PLUGIN_DIR.'/vendor/autoload.php')) { throw new \RuntimeException('Библиотека Excel не установлена. Выполните composer install.'); }
        require_once PLUGIN_DIR.'/vendor/autoload.php';
    }

    public static function read(string $path): array
    {
        self::loader();
        if (!is_file($path) || filesize($path)>8*1024*1024) { throw new \RuntimeException('Максимальный размер книги — 8 МБ.'); }
        $zip=new \ZipArchive();
        if ($zip->open($path)!==true) { throw new \RuntimeException('Загрузите настоящую книгу .xlsx.'); }
        try {
            $size=0;
            if ($zip->numFiles>2000) { throw new \RuntimeException('В книге слишком много внутренних файлов.'); }
            for ($i=0;$i<$zip->numFiles;$i++) {
                $entry=$zip->statIndex($i); $size+=$entry['size'];
                if ($size>32*1024*1024 || preg_match('~(?:vbaProject|externalLinks|embeddings)~i',$entry['name'])) {
                    throw new \RuntimeException('Макросы, внешние связи, вложенные файлы и распакованные книги больше 32 МБ не поддерживаются.');
                }
            }
        } finally { $zip->close(); }
        $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['Устройства','Услуги и цены']);
        foreach ($reader->listWorksheetInfo($path) as $sheet) {
            $max=$sheet['worksheetName']==='Устройства' ? 1007 : 5007;
            if (in_array($sheet['worksheetName'],['Устройства','Услуги и цены'],true) && ($sheet['totalRows']>$max || $sheet['totalColumns']>30)) {
                throw new \RuntimeException('Лимит: 1000 устройств и 5000 строк услуг. Удалите лишние пустые строки за таблицей.');
            }
        }
        $book=$reader->load($path);
        try {
            $props=$book->getProperties();
            $revision=$props->isCustomPropertySet('service101_revision') ? (int)$props->getCustomPropertyValue('service101_revision') : null;
            $schema=$props->isCustomPropertySet('service101_schema') ? (int)$props->getCustomPropertyValue('service101_schema') : 1;
            if ($schema!==1) { throw new \RuntimeException('Неизвестная версия шаблона Excel. Скачайте свежий экспорт.'); }
            $result=['devices'=>[], 'prices'=>[], 'revision'=>$revision];
            foreach ([['Устройства',self::DEVICE_HEADERS,self::DEVICE_KEYS,'devices'],['Услуги и цены',self::PRICE_HEADERS,self::PRICE_KEYS,'prices']] as [$title,$headers,$keys,$kind]) {
                $sheet=$book->getSheetByName($title);
                if (!$sheet) { throw new \RuntimeException('В книге отсутствует лист «'.$title.'».'); }
                foreach ($headers as $i=>$header) {
                    if (trim((string)$sheet->getCell([$i+1,7])->getValue())!==$header) { throw new \RuntimeException('Лист «'.$title.'», строка 7: ожидается столбец «'.$header.'».'); }
                }
                for ($row=8;$row<=$sheet->getHighestDataRow();$row++) {
                    $record=['_row'=>$row, '_sheet'=>$title]; $nonempty=false;
                    foreach ($keys as $i=>$key) {
                        if ($kind==='prices' && $key==='device_name') { continue; }
                        $cell=$sheet->getCell([$i+1,$row]);
                        if ($cell->getDataType()==='f') { throw new \RuntimeException("Лист «{$title}», строка $row: формула в поле «{$headers[$i]}» недопустима. Вставьте значение."); }
                        $value=trim((string)$cell->getValue());
                        if (mb_strlen($value)>10000) { throw new \RuntimeException("Лист «{$title}», строка $row: слишком длинное значение."); }
                        $record[$key]=$value; $nonempty=$nonempty || $value!=='';
                    }
                    if ($nonempty) { $result[$kind][]=$record; }
                }
            }
            return $result;
        } finally { $book->disconnectWorksheets(); }
    }

    public static function export(string $path): void
    {
        self::loader();
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getProperties()->setCreator('Сервис 101')->setTitle('Каталог устройств и цены')->setCustomProperty('service101_schema',1)->setCustomProperty('service101_revision',Catalog::revision());
        $devices=Catalog::devices(true); $prices=Catalog::prices();
        uasort($prices,static fn($a,$b)=>strnatcasecmp($a['device_code'],$b['device_code']) ?: $a['order']<=>$b['order'] ?: strcmp($a['service_code'],$b['service_code']));
        $lookup_values=[];
        $configs=[['Устройства',self::DEVICE_HEADERS,self::DEVICE_KEYS,$devices,'Devices'],['Услуги и цены',self::PRICE_HEADERS,self::PRICE_KEYS,$prices,'DeviceServices']];
        foreach ($configs as $index=>[$title,$headers,$keys,$rows,$table_name]) {
            $sheet=$index===0 ? $book->getActiveSheet() : $book->createSheet(); $sheet->setTitle($title);
            $last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
            $sheet->mergeCells("A1:{$last}2")->setCellValue('A1','СЕРВИС 101 / '.$title);
            $sheet->mergeCells("A3:{$last}3")->setCellValue('A3','Редактируйте строки с 8-й. Постоянные коды связывают данные. Новые устройства сначала сохраняйте как «Черновик».');
            $sheet->mergeCells("A4:{$last}4")->setCellValue('A4','Пустое поле = оставить прежнее значение. [очистить] = удалить значение. Отсутствующая строка ничего не удаляет.');
            $sheet->mergeCells("A5:{$last}5")->setCellValue('A5','Экспорт: '.wp_date('d.m.Y H:i').' · Версия каталога: '.Catalog::revision().' · Подробности — на листе «Инструкция».');
            $sheet->fromArray($headers,null,'A7'); $r=8;
            foreach ($rows as $data) {
                $data['device_name']=$devices[$data['device_code']??'']['name']??'';
                if ($index===0) { foreach (['image1','image2','image3'] as $key) { if (!empty($data[$key.'_id'])) { $data[$key]=wp_get_attachment_url((int)$data[$key.'_id']); } } }
                foreach ($keys as $c=>$key) {
                    $value=$data[$key]??'';
                    $numeric=in_array($key,['work_amount','total_amount','order'],true) && $value!==null && $value!=='';
                    $sheet->setCellValueExplicit([$c+1,$r], $value??'', $numeric ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                if ($index===1) {
                    $sheet->setCellValue('B'.$r,'=IF(A'.$r.'="","",IFNA(VLOOKUP(A'.$r.',\'Устройства\'!$A$8:$B$1007,2,FALSE),"Проверьте код"))');
                    $lookup_values[$title.'!B'.$r]=$data['device_name'];
                }
                $sheet->getRowDimension($r)->setRowHeight(40);
                $r++;
            }
            $end=max(8,$r-1);
            $table=new \PhpOffice\PhpSpreadsheet\Worksheet\Table("A7:$last$end",$table_name);
            $table->setStyle((new \PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle())->setTheme('TableStyleMedium2')->setShowRowStripes(true));
            $sheet->addTable($table); $sheet->freezePane('C8'); $sheet->setSelectedCell('B8');
            $sheet->setShowGridlines(false);
            $sheet->getStyle("A3:$last$end")->getFont()->setName('Arial')->setSize(10);
            $sheet->getStyle("A1:{$last}2")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF123A43');
            $sheet->getStyle('A1')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'))->setBold(true)->setSize(22);
            $sheet->getStyle("A7:$last$end")->getAlignment()->setVertical('top')->setWrapText(true);
            $sheet->getStyle("A7:{$last}7")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
            $sheet->getStyle("A7:{$last}7")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF176779');
            $sheet->getRowDimension(7)->setRowHeight(36);
            if ($index===1) {
                foreach (['F','H'] as $column) { $sheet->getStyle($column.'8:'.$column.$end)->getNumberFormat()->setFormatCode('#,##0.00'); }
                $sheet->getStyle('B8:B'.$end)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFEEF4FF');
            }
            foreach ($keys as $c=>$key) { $sheet->getColumnDimensionByColumn($c+1)->setWidth(in_array($key,['description','name','path','image1','image2','image3'],true) ? 42 : 20); }
            $choices=$index===0 ? ['E'=>['Черновик','Опубликовать','Скрыть']] : ['E'=>['Фиксированная','От','Бесплатно','По запросу'],'G'=>['Фиксированная','Ориентир','От','Бесплатно','После диагностики'],'L'=>['Обновить','Скрыть']];
            foreach ($choices as $column=>$values) {
                $validation=new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
                $validation->setType('list')->setErrorStyle('stop')->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setErrorTitle('Выберите значение')->setError('Используйте значение из списка.')->setFormula1('"'.implode(',',$values).'"');
                $sheet->setDataValidation($column.'8:'.$column.($index===0?1007:5007),$validation);
            }
        }
        $guide=$book->createSheet()->setTitle('Инструкция');
        $guide->getColumnDimension('A')->setWidth(110);
        $lines=['СЕРВИС 101 — работа с Excel','1. Скачайте свежий экспорт из WordPress перед редактированием.','2. Устройства: одна строка на модель. Код устройства постоянный; для новой модели задайте новый код, например D0118.','3. Услуги и цены: одна строка на пару устройства и услуги. Общий код услуги должен иметь одинаковое название во всех строках.','4. Вставляйте прямую HTTPS-ссылку на JPG, PNG или WebP. Фотографии будут загружены в медиатеку.','5. Числовые цены вводите без ₽. «Бесплатно» — ноль; «По запросу» и «После диагностики» — без числа.','6. Пустые необязательные поля сохраняют прежние данные. Для очистки напишите [очистить].','7. Скрывайте услуги и устройства явно через «Действие» и «Публикация». Пропущенные строки не удаляются.','8. Загрузите книгу в «Каталог → Импорт Excel», проверьте отчёт и примените изменения. Ошибки блокируют весь пакет.','9. Если каталог изменился после выгрузки или проверки, скачайте новый экспорт и повторите изменения.','10. Последний применённый пакет можно восстановить из журнала, пока каталог не изменён снова.','Лимиты: 8 МБ, 1000 устройств, 5000 строк услуг. Не используйте макросы и формулы в импортируемых полях.'];
        foreach ($lines as $i=>$line) { $guide->setCellValue('A'.($i+1),$line); $guide->getRowDimension($i+1)->setRowHeight($i===0?36:44); }
        $guide->getStyle('A1:A12')->getAlignment()->setWrapText(true)->setVertical('center'); $guide->getStyle('A1')->getFont()->setBold(true)->setSize(20);
        $book->setActiveSheetIndex(0);
        // The lookup results are already known from the same exported device map.
        // Seed the public calculation cache so exporting 1941 rows avoids repeated table scans.
        $calculation=\PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($book);
        foreach ($lookup_values as $cell=>$value) { $calculation->saveValueToCache($cell,$value); }
        $writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book); $writer->setPreCalculateFormulas(true); $writer->save($path); $book->disconnectWorksheets();
    }
}
