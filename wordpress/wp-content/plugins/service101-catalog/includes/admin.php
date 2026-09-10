<?php
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;

final class Admin
{
    public static function menu(): void
    {
        add_menu_page('Каталог Сервис 101','Каталог','manage_s101_catalog','s101-catalog',[self::class,'page'],'dashicons-smartphone',21);
        add_submenu_page('s101-catalog','Устройства','Устройства','manage_s101_catalog','s101-catalog',[self::class,'page']);
        add_submenu_page('s101-catalog','Категории','Категории','manage_s101_catalog','s101-categories',[self::class,'page']);
        add_submenu_page('s101-catalog','Бренды','Бренды','manage_s101_catalog','s101-brands',[self::class,'page']);
        add_submenu_page('s101-catalog','Импорт Excel','Импорт Excel','import_s101_catalog','s101-import',[self::class,'page']);
        add_submenu_page('s101-catalog','История изменений','История изменений','manage_s101_catalog','s101-history',[self::class,'page']);
    }
    private static function url(array $args=[]): string { return add_query_arg(array_merge(['page'=>'s101-catalog'],$args),admin_url('admin.php')); }
    private static function form(string $operation,array $hidden=[]): void
    {
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('s101_catalog');
        foreach (array_merge(['action'=>'s101_catalog','operation'=>$operation],$hidden) as $name=>$value) { echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'">'; }
    }
    public static function action(): void
    {
        if (!current_user_can('manage_s101_catalog')) { wp_die('Недостаточно прав.',403); }
        check_admin_referer('s101_catalog');
        $operation=sanitize_key($_POST['operation']??'');
        try {
            if (in_array($operation,['upload','export','apply','restore'],true) && !current_user_can('import_s101_catalog')) { throw new \RuntimeException('Нет прав для импорта и экспорта.'); }
            if ($operation==='upload') {
                $file=$_FILES['workbook']??[];
                if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'') || strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION))!=='xlsx') { throw new \RuntimeException('Выберите книгу .xlsx до 8 МБ.'); }
                $plan=Import::preview(Workbook::read($file['tmp_name'])); $id=Import::save_plan($plan);
            } elseif ($operation==='export') {
                $temp=wp_tempnam('service101-export'); Workbook::export($temp);
                nocache_headers(); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="service101-catalog-'.gmdate('Y-m-d').'.xlsx"'); header('Content-Length: '.filesize($temp)); readfile($temp); wp_delete_file($temp); exit;
            } elseif (in_array($operation,['save_category','save_brand'],true)) {
                $taxonomy=$operation==='save_category'?'s101_category':'s101_brand';
                $term_input=wp_unslash($_POST['term']??[]);
                if (!is_array($term_input)) { throw new \RuntimeException('Некорректная форма справочника.'); }
                if ($taxonomy==='s101_category' && !isset($term_input['home_enabled'])) { $term_input['home_enabled']='0'; }
                $term=Catalog::save_term($taxonomy,$term_input);
                wp_safe_redirect(self::url(['page'=>$taxonomy==='s101_category'?'s101-categories':'s101-brands','saved'=>$term->term_id])); exit;
            } elseif ($operation==='save') {
                $device=wp_unslash($_POST['device']??[]); $rows=wp_unslash($_POST['prices']??[]);
                if (!is_array($device) || !is_array($rows) || count($rows)>100) { throw new \RuntimeException('Некорректная форма устройства.'); }
                $prices=[];
                foreach ($rows as $row) { if (!is_array($row)) { throw new \RuntimeException('Некорректная строка услуги.'); } if (empty($row['service_code']) && empty($row['name'])) { continue; } $row['device_code']=$device['code']??''; $prices[]=$row; }
                $copy=sanitize_text_field(wp_unslash($_POST['copy_from']??''));
                if ($copy!=='') { foreach (Catalog::prices($copy) as $row) { $row['device_code']=$device['code']??''; $prices[]=$row; } }
                $plan=Import::preview(['devices'=>[$device],'prices'=>$prices,'revision'=>(int)($_POST['revision']??-1)]); $id=Import::save_plan($plan);
            } elseif ($operation==='apply') { $id=absint($_POST['batch']??0); Import::apply($id); }
            elseif ($operation==='restore') { $id=absint($_POST['batch']??0); Import::restore($id); }
            else { throw new \RuntimeException('Неизвестное действие.'); }
            wp_safe_redirect(self::url(['batch'=>$id])); exit;
        } catch (\Throwable $error) { wp_die(esc_html($error->getMessage()).'<p><a href="'.esc_url(self::url(['page'=>'s101-import'])).'">Вернуться в каталог</a></p>','Каталог Сервис 101',['response'=>400]); }
    }
    public static function page(): void
    {
        if (!current_user_can('manage_s101_catalog')) { return; }
        echo '<div class="wrap s101-admin"><h1>Каталог Сервис 101</h1><p>Устройства, услуги и цены · версия '.Catalog::revision().'</p>';
        echo '<style>.s101-admin .card{max-width:none;padding:24px;margin:20px 0}.s101-admin label{display:block;font-weight:600;margin:12px 0 4px}.s101-admin input:not([type=hidden]),.s101-admin select,.s101-admin textarea{width:100%;max-width:100%}.s101-admin .s101-grid{display:grid;grid-template-columns:repeat(2,minmax(200px,1fr));gap:16px}.s101-admin td{vertical-align:top}.s101-admin .s101-scroll{overflow:auto}.s101-admin .s101-prices{min-width:1400px}.s101-admin .s101-prices input{min-width:90px}.s101-admin .button{width:auto!important}.s101-admin .s101-good{color:#087648}.s101-admin .s101-bad{color:#b32d2e}@media(max-width:782px){.s101-admin .s101-grid{grid-template-columns:1fr}}</style>';
        try {
            if (isset($_GET['batch'])) { self::report(absint($_GET['batch'])); }
            elseif (isset($_GET['edit'])) { self::editor(sanitize_text_field(wp_unslash($_GET['edit']))); }
            elseif (($_GET['page']??'')==='s101-import') { self::upload(); }
            elseif (($_GET['page']??'')==='s101-categories') { self::terms('s101_category'); }
            elseif (($_GET['page']??'')==='s101-brands') { self::terms('s101_brand'); }
            elseif (($_GET['page']??'')==='s101-history') { self::history(); }
            else { self::listing(); }
        } catch (\Throwable $error) { echo '<div class="notice notice-error"><p>'.esc_html($error->getMessage()).'</p></div>'; }
        echo '</div>';
    }
    private static function listing(): void
    {
        echo '<p><a class="button button-primary" href="'.esc_url(self::url(['edit'=>'new'])).'">Добавить устройство</a> <a class="button" href="'.esc_url(self::url(['page'=>'s101-import'])).'">Импорт и экспорт Excel</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Код</th><th>Устройство</th><th>Категория / бренд</th><th>Публикация</th><th>Услуги</th><th>Просмотр</th></tr></thead><tbody>';
        $counts=[]; foreach (Catalog::prices() as $price) { $counts[$price['device_code']]=($counts[$price['device_code']]??0)+1; }
        foreach (Catalog::devices(true) as $code=>$d) { echo '<tr><td>'.esc_html($code).'</td><td><a href="'.esc_url(self::url(['edit'=>$code])).'"><strong>'.esc_html($d['name']).'</strong></a></td><td>'.esc_html($d['category'].' / '.$d['brand']).'</td><td>'.esc_html($d['publication']).'</td><td>'.($counts[$code]??0).'</td><td><a href="'.esc_url(home_url($d['path'])).'" target="_blank" rel="noopener">Открыть</a></td></tr>'; }
        echo '</tbody></table><p>Черновики видны на сайте только вошедшему администратору каталога. Посетители получают 404.</p>';
    }
    private static function terms(string $taxonomy): void
    {
        $is_category=$taxonomy==='s101_category'; $label=$is_category?'Категория':'Бренд';
        $editing=absint($_GET['edit_term']??0); $term=$editing?get_term($editing,$taxonomy):null;
        if ($editing && !$term instanceof \WP_Term) { throw new \RuntimeException('Элемент справочника не найден.'); }
        $data=$term instanceof \WP_Term ? ['term_id'=>$term->term_id,'name'=>$term->name,'slug'=>$term->slug] : [];
        foreach (['sort_order','home_enabled','home_image_id','catalog_title','catalog_subtitle','catalog_intro','info_title','info_text','info_items'] as $key) { $data[$key]=$term?get_term_meta($term->term_id,'s101_'.$key,true):''; }
        echo '<p>Справочник управляет вариантами в карточке устройства и каталогом. Адрес создаётся один раз и не меняется после появления моделей.</p>';
        echo '<div class="card"><h2>'.($term?'Изменить':'Новый').' '.$label.'</h2>'; self::form($is_category?'save_category':'save_brand');
        echo '<input type="hidden" name="term[term_id]" value="'.absint($data['term_id']??0).'">';
        self::field('term[name]','Название',$data['name']??''); self::field('term[slug]','Код адреса (латиницей, только при создании)',$data['slug']??'',[],(bool)$term);
        self::field('term[sort_order]','Порядок показа',$data['sort_order']??'100');
        if ($is_category) {
            echo '<p><label><input type="checkbox" name="term[home_enabled]" value="1" '.checked($data['home_enabled']??'', '1',false).'> Показывать карточку категории на главной</label></p>';
            self::field('term[catalog_title]','Заголовок в каталоге',$data['catalog_title']??''); self::field('term[catalog_subtitle]','Подзаголовок цен',$data['catalog_subtitle']??'');
            echo '<label for="s101-term-catalog-intro">Короткое описание</label><textarea id="s101-term-catalog-intro" name="term[catalog_intro]" rows="3">'.esc_textarea($data['catalog_intro']??'').'</textarea>';
            self::field('term[info_title]','Заголовок блока «Важно знать»',$data['info_title']??'');
            echo '<label for="s101-term-info-text">Текст блока «Важно знать»</label><textarea id="s101-term-info-text" name="term[info_text]" rows="3">'.esc_textarea($data['info_text']??'').'</textarea>';
            echo '<label for="s101-term-info-items">Пункты блока «Важно знать» — по одному на строку</label><textarea id="s101-term-info-items" name="term[info_items]" rows="4">'.esc_textarea($data['info_items']??'').'</textarea>';
        }
        submit_button($term?'Сохранить':'Создать '.$label); echo '</form></div>';
        echo '<h2>Существующие '.($is_category?'категории':'бренды').'</h2><table class="widefat striped"><thead><tr><th>Название</th><th>Адрес</th><th>Модели</th><th></th></tr></thead><tbody>';
        foreach (Catalog::terms($taxonomy) as $item) { $count=(int)$item->count; echo '<tr><td>'.esc_html($item->name).'</td><td><code>'.esc_html($item->slug).'</code></td><td>'.$count.'</td><td><a href="'.esc_url(self::url(['page'=>$is_category?'s101-categories':'s101-brands','edit_term'=>$item->term_id])).'">Изменить</a></td></tr>'; }
        echo '</tbody></table>';
    }
    private static function upload(): void
    {
        echo '<div class="card"><h2>1. Скачайте актуальную таблицу</h2><p>В книге два связанных листа: устройство заполняется один раз, услуги и цены — отдельно. Коды связывают строки; названия можно менять.</p>';
        self::form('export'); submit_button('Скачать Excel с текущими данными','secondary','submit',false); echo '</form></div>';
        echo '<div class="card"><h2>2. Загрузите изменения</h2><p>Книга .xlsx до 8 МБ. До применения вы увидите ошибки и изменения цен. Пропущенные строки сохранятся на сайте.</p>';
        self::form('upload'); echo '<label for="s101-workbook">Книга Excel</label><input id="s101-workbook" type="file" name="workbook" accept=".xlsx" required>'; submit_button('Проверить книгу'); echo '</form></div>';
    }
    private static function report(int $id): void
    {
        $batch=Import::batch($id); $plan=$batch['plan'];
        $labels=['preview'=>'Предварительная проверка','applied'=>'Изменения применены','restored'=>'Пакет восстановлен'];
        echo '<h2>'.esc_html($labels[$batch['state']]??$batch['state']).' · пакет №'.$id.'</h2><p>Новых: '.$plan['counts']['new'].' · изменённых: '.$plan['counts']['changed'].' · скрываемых: '.$plan['counts']['hidden'].' · без изменений: '.$plan['counts']['same'].'</p>';
        foreach ($plan['errors'] as $error) { echo '<p class="s101-bad">'.esc_html($error).'</p>'; }
        foreach ($plan['warnings'] as $warning) { echo '<p class="notice notice-warning">'.esc_html($warning).'</p>'; }
        if ($batch['state']==='preview' && !$plan['errors']) { self::form('apply',['batch'=>$id]); submit_button('Применить проверенные изменения','primary','submit',false); echo '</form>'; }
        if ($batch['state']==='applied' && Catalog::revision()===(int)$batch['revision']+1) { self::form('restore',['batch'=>$id]); echo '<p>Восстановление вернёт весь каталог к состоянию перед этим пакетом.</p>'; submit_button('Восстановить состояние перед пакетом','secondary','submit',false); echo '</form>'; }
        echo '<div class="card"><h2>Устройства</h2><table class="widefat striped"><thead><tr><th>Строка</th><th>Код</th><th>Название</th><th>Публикация</th><th>Адрес</th></tr></thead><tbody>';
        foreach ($plan['devices'] as $key=>$change) { $d=$change['after']; echo '<tr><td>'.$change['row'].'</td><td>'.esc_html($key).'</td><td>'.esc_html($d['name']).'</td><td>'.esc_html($d['publication']).'</td><td>'.esc_html($d['path']).'</td></tr>'; }
        echo '</tbody></table></div><div class="card"><h2>Услуги и цены</h2><p>Название общего кода услуги обновится у всех устройств, где используется этот код.</p><table class="widefat striped"><thead><tr><th>Строка</th><th>Устройство / услуга</th><th>Название</th><th>Работа: до → после</th><th>С деталью: до → после</th><th>Действие</th></tr></thead><tbody>';
        foreach ($plan['prices'] as $key=>$change) {
            if ($change['state']==='same') { continue; }
            $d=$change['after']; $old=$change['before'];
            echo '<tr><td>'.$change['row'].'</td><td>'.esc_html($key).'</td><td>'.esc_html($d['name']).'</td>';
            foreach (['work','total'] as $part) { echo '<td>'.esc_html(($old?Catalog::price_text($old[$part.'_type'],$old[$part.'_amount']):'—').' → '.Catalog::price_text($d[$part.'_type'],$d[$part.'_amount'])).'</td>'; }
            echo '<td>'.esc_html($d['action']).'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    private static function history(): void
    {
        global $wpdb;
        $rows=$wpdb->get_results('SELECT id,created,state,author FROM '.Catalog::table('batches').' ORDER BY id DESC LIMIT 50',ARRAY_A);
        echo '<table class="widefat striped"><thead><tr><th>Пакет</th><th>Дата UTC</th><th>Статус</th></tr></thead><tbody>';
        foreach ($rows as $row) { echo '<tr><td><a href="'.esc_url(self::url(['batch'=>$row['id']])).'">№'.(int)$row['id'].'</a></td><td>'.esc_html($row['created']).'</td><td>'.esc_html($row['state']).'</td></tr>'; }
        echo '</tbody></table>';
    }
    private static function field(string $name,string $label,mixed $value='',array $choices=[],bool $readonly=false): void
    {
        $id='s101-'.preg_replace('/[^a-z0-9]/i','-',$name); echo '<label for="'.esc_attr($id).'">'.esc_html($label).'</label>';
        if ($choices) { echo '<select id="'.esc_attr($id).'" name="'.esc_attr($name).'">'; foreach ($choices as $choice) { echo '<option '.selected((string)$value,$choice,false).'>'.esc_html($choice).'</option>'; } echo '</select>'; }
        else { echo '<input id="'.esc_attr($id).'" name="'.esc_attr($name).'" value="'.esc_attr((string)($value??'')).'" '.($readonly?'readonly':'').'>'; }
    }
    private static function editor(string $code): void
    {
        $devices=Catalog::devices(true); $device=$devices[$code]??[];
        if ($code!=='new' && !$device) { throw new \RuntimeException('Устройство не найдено.'); }
        echo '<h2>'.esc_html($device['name']??'Новое устройство').'</h2>';
        self::form('save',['revision'=>Catalog::revision()]);
        echo '<div class="card"><div class="s101-grid">';
        foreach (Workbook::DEVICE_KEYS as $i=>$key) {
            echo '<div>';
            self::field('device['.$key.']',Workbook::DEVICE_HEADERS[$i],$device[$key]??($key==='publication'?'Черновик':''),$key==='publication'?['Черновик','Опубликовать','Скрыть']:[],!empty($device)&&in_array($key,['code','path','category_slug','brand_slug','model_slug'],true));
            echo '</div>';
        }
        echo '</div><p>Фото: прямая HTTPS-ссылка. Служебные коды адреса новой модели можно оставить пустыми — они появятся в отчёте. Для очистки необязательного поля введите [очистить].</p></div><div class="card"><h2>Услуги и цены</h2><div class="s101-scroll"><table class="widefat s101-prices"><thead><tr>';
        $keys=array_values(array_diff(Workbook::PRICE_KEYS,['device_code','device_name']));
        foreach ($keys as $key) { echo '<th>'.esc_html(Workbook::PRICE_HEADERS[array_search($key,Workbook::PRICE_KEYS,true)]).'</th>'; }
        echo '</tr></thead><tbody id="s101-price-rows">';
        $rows=array_values(Catalog::prices($code)); $rows=array_merge($rows,[[],[],[]]);
        foreach ($rows as $i=>$row) { self::price_row($i,$row,$keys); }
        echo '</tbody></table></div><p><button class="button" type="button" id="s101-add-price">Добавить строку услуги</button></p><template id="s101-price-template">'; self::price_row('__ROW__',[],$keys); echo '</template>';
        echo '<script>document.getElementById("s101-add-price").addEventListener("click",function(){const body=document.getElementById("s101-price-rows");body.insertAdjacentHTML("beforeend",document.getElementById("s101-price-template").innerHTML.replaceAll("__ROW__",String(body.rows.length)));});</script>';
        if (!$device || !Catalog::prices($code)) {
            echo '<label for="s101-copy">Скопировать набор работ с устройства</label><select name="copy_from" id="s101-copy"><option value="">Не копировать</option>';
            foreach ($devices as $other_code=>$other) { echo '<option value="'.esc_attr($other_code).'">'.esc_html($other['name']).'</option>'; }
            echo '</select><p>При копировании оставьте таблицу выше пустой; затем отредактируйте отличия в скопированных работах.</p>';
        }
        echo '</div>'; submit_button('Проверить изменения перед сохранением'); echo '</form>';
    }
    private static function price_row(int|string $i,array $row,array $keys): void
    {
        echo '<tr>';
        $choices=['work_type'=>['Фиксированная','От','Бесплатно','По запросу'],'total_type'=>['Фиксированная','Ориентир','От','Бесплатно','После диагностики'],'action'=>['Обновить','Скрыть']];
        foreach ($keys as $key) { echo '<td>'; self::field('prices['.$i.']['.$key.']','',$row[$key]??'', $choices[$key]??[], $key==='service_code'&&!empty($row)); echo '</td>'; }
        echo '</tr>';
    }
}
