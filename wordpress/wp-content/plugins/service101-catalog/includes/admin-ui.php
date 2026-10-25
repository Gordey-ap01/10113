<?php
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;

/** Catalog navigation and editor presentation. Catalog writes stay in Admin / Import. */
final class AdminUI
{
    public static function assets(): void
    {
        if (!str_starts_with(sanitize_key($_GET['page']??''),'s101-')) { return; }
        $base=plugins_url('assets/',dirname(__DIR__).'/service101-catalog.php');
        foreach (['css','js'] as $extension) {
            $version=(string)filemtime(dirname(__DIR__).'/assets/admin.'.$extension);
            if ($extension==='css') { wp_enqueue_style('s101-admin',$base.'admin.css',[],$version); }
            else { wp_enqueue_script('s101-admin',$base.'admin.js',[],$version,true); }
        }
    }

    private static function url(array $args=[]): string
    {
        return add_query_arg(['page'=>'s101-catalog']+$args,admin_url('admin.php'));
    }

    public static function listing(): void
    {
        $category=sanitize_key($_GET['category']??''); $brand=sanitize_key($_GET['brand']??'');
        $devices=Catalog::devices(true); $categories=Catalog::terms('s101_category'); $brands=Catalog::terms('s101_brand');
        $category_order=array_flip(array_keys(Catalog::categories(true)));
        usort($categories,static fn($a,$b)=>($category_order[$a->slug]??1000)<=>($category_order[$b->slug]??1000));
        if ($category) { usort($brands,static fn($a,$b)=>(Catalog::brand_order($a,$category)<=>Catalog::brand_order($b,$category)) ?: strnatcasecmp($a->name,$b->name)); }
        $usage=Catalog::brand_usage($devices); $counts=[]; $service_counts=[];
        foreach ($devices as $device) { $counts[$device['category_slug']][$device['brand_slug']]=($counts[$device['category_slug']][$device['brand_slug']]??0)+1; }
        foreach (Catalog::prices() as $price) { $service_counts[$price['device_code']]=($service_counts[$price['device_code']]??0)+1; }
        $category_term=$category?Catalog::term_by_slug('s101_category',$category):null;
        $brand_term=$brand?Catalog::term_by_slug('s101_brand',$brand):null;
        if (($category && !$category_term) || ($brand && !$brand_term)) { throw new \InvalidArgumentException('Раздел не найден. Выберите категорию в каталоге.'); }
        echo '<div class="s101-browser"><aside class="s101-tree" aria-label="Категории и бренды"><a class="s101-tree-root" href="'.esc_url(self::url()).'">Устройства/Модели</a>';
        foreach ($categories as $term) {
            echo '<details '.($category===$term->slug?'open':'').'><summary>'.esc_html($term->name).'</summary><a href="'.esc_url(self::url(['category'=>$term->slug])).'">Все бренды категории</a>';
            foreach ($brands as $item) {
                if (!in_array($term->slug,Catalog::brand_categories($item,$usage),true)) { continue; }
                echo '<a '.($category===$term->slug && $brand===$item->slug?'aria-current="page"':'').' href="'.esc_url(self::url(['category'=>$term->slug,'brand'=>$item->slug])).'">'.esc_html($item->name).' <small>'.($counts[$term->slug][$item->slug]??0).'</small></a>';
            }
            echo '</details>';
        }
        echo '</aside><section class="s101-browser-main"><nav class="s101-breadcrumbs" aria-label="Путь в каталоге"><a href="'.esc_url(self::url()).'">Каталог</a>';
        if ($category_term) { echo ' <span aria-hidden="true">/</span> <a href="'.esc_url(self::url(['category'=>$category])).'">'.esc_html($category_term->name).'</a>'; }
        if ($brand_term) { echo ' <span aria-hidden="true">/</span> <strong>'.esc_html($brand_term->name).'</strong>'; }
        echo '</nav><div class="s101-toolbar"><h2>'.esc_html($brand_term?($brand_term->name.' — Устройства/Модели'):($category_term?($category_term->name.' — Бренды'):'Категории устройств')).'</h2>';
        echo '<a class="button button-primary" href="'.esc_url(self::url(['edit'=>'new','category'=>$category,'brand'=>$brand])).'">Добавить устройство/модель</a></div><p>Откройте строку, чтобы перейти дальше. У модели можно открыть услуги в отдельном окне.</p><label class="screen-reader-text" for="s101-search">Поиск в текущей таблице</label><input id="s101-search" type="search" autocomplete="off" placeholder="Поиск в текущей таблице…">';
        if (!$category && !$brand) {
            echo '<table class="widefat s101-directory"><thead><tr><th>Категория</th><th>Бренды</th><th>Устройства/Модели</th></tr></thead><tbody>';
            foreach ($categories as $term) {
                $url=self::url(['category'=>$term->slug]);
                $brand_count=count(array_filter($brands,static fn($b)=>in_array($term->slug,Catalog::brand_categories($b,$usage),true)));
                echo '<tr data-href="'.esc_url($url).'"><td><a href="'.esc_url($url).'"><strong>'.esc_html($term->name).'</strong></a></td><td>'.$brand_count.'</td><td>'.array_sum($counts[$term->slug]??[]).'</td></tr>';
            }
        } elseif (!$brand) {
            echo '<table class="widefat s101-directory"><thead><tr><th>Бренд</th><th>Устройства/Модели</th><th>Порядок</th><th>Настройки</th></tr></thead><tbody>';
            foreach ($brands as $item) {
                if (!in_array($category,Catalog::brand_categories($item,$usage),true)) { continue; }
                $url=self::url(['category'=>$category,'brand'=>$item->slug]);
                echo '<tr data-href="'.esc_url($url).'"><td><a href="'.esc_url($url).'"><strong>'.esc_html($item->name).'</strong></a></td><td>'.($counts[$category][$item->slug]??0).'</td><td>'.Catalog::brand_order($item,$category).'</td><td><a href="'.esc_url(add_query_arg(['page'=>'s101-brands','edit_term'=>$item->term_id],admin_url('admin.php'))).'">Изменить бренд</a></td></tr>';
            }
        } else {
            echo '<table class="widefat s101-directory"><thead><tr><th>Устройство/Модель</th><th>Публикация</th><th>Услуги</th><th>Порядок</th><th>Действия</th></tr></thead><tbody>';
            $visible=0;
            foreach ($devices as $code=>$device) {
                if ($device['brand_slug']!==$brand || ($category && $device['category_slug']!==$category)) { continue; }
                $visible++; $url=self::url(['edit'=>$code]); $popup=self::url(['edit'=>$code,'embedded'=>'1','services_only'=>'1']);
                echo '<tr data-href="'.esc_url($url).'"><td><a href="'.esc_url($url).'"><strong>'.esc_html($device['name']).'</strong></a><small class="s101-subtext">'.esc_html($device['category'].' · '.$device['brand']).'</small></td><td>'.esc_html($device['publication']).'</td><td>'.($service_counts[$code]??0).'</td><td>'.(int)$device['model_order'].'</td><td><div class="s101-actions"><a class="button" href="'.esc_url($popup).'" data-services-popup>Услуги и цены</a><a href="'.esc_url(home_url($device['path'])).'" target="_blank" rel="noopener">На сайте ↗</a><form method="post" data-s101-form data-operation="delete_device" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('s101_catalog');
                foreach (['action'=>'s101_catalog','operation'=>'delete_device','code'=>$code,'revision'=>Catalog::revision(),'confirm_delete'=>'1'] as $key=>$value) { echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">'; }
                echo '<button class="button s101-delete" type="submit" data-confirm="Удалить устройство полностью вместе со всеми услугами и ценами?">Удалить полностью</button></form></div></td></tr>';
            }
            if (!$visible) { echo '<tr><td colspan="5"><h3>Моделей пока нет</h3><p>Бренд уже доступен на сайте. Добавьте первую модель и её услуги кнопкой выше.</p></td></tr>'; }
        }
        echo '</tbody></table><p id="s101-no-results" hidden>Ничего не найдено. Измените запрос или выберите другой раздел.</p></section></div><dialog class="s101-editor-dialog" aria-labelledby="s101-editor-title"><div class="s101-dialog-head"><h2 id="s101-editor-title">Услуги и цены модели</h2><button type="button" class="button" data-close-editor>Закрыть</button></div><iframe title="Редактор услуг модели"></iframe></dialog>';
    }

    public static function services(array $rows): void
    {
        if (!$rows) { $rows=[[]]; }
        echo '<p class="s101-mobile-hint">Прокрутите таблицу в сторону, чтобы увидеть цену, срок и пометку.</p><div class="s101-scroll" role="region" aria-label="Таблица услуг с горизонтальной прокруткой" tabindex="0"><table class="widefat s101-service-table"><thead><tr><th class="s101-check-col"><input type="checkbox" data-select-all aria-label="Выбрать все услуги"></th><th>Услуга и описание</th><th>Цена с деталью, ₽</th><th>Срок ремонта</th><th>Пометка</th><th class="s101-order-col">Порядок</th></tr></thead><tbody id="s101-price-rows">';
        foreach ($rows as $index=>$row) { self::price_row($index,$row); }
        echo '</tbody></table></div><div class="s101-bulkbar"><button class="button" type="button" id="s101-add-price">+ Добавить услугу</button><span data-selected-count role="status" aria-live="polite">Выбрано: 0</span><button class="button s101-delete" type="button" data-delete-selected disabled>Удалить выбранные</button><button class="button" type="button" data-undo-delete hidden>Вернуть удалённые строки</button></div><p class="description">Удаление только помечает строки. Они исчезнут с сайта после проверки и применения изменений.</p><template id="s101-price-template">';
        self::price_row('__ROW__',[]); echo '</template>';
    }

    private static function price_row(int|string $index,array $row): void
    {
        $prefix='prices['.$index.']'; $deleted=($row['_delete']??'')==='1';
        echo '<tr class="'.($deleted?'s101-row-deleted':'').'"><td><input type="checkbox" data-service-select aria-label="Выбрать услугу '.esc_attr((string)($row['name']??'новая')).'"><input type="hidden" name="'.esc_attr($prefix).'[service_code]" value="'.esc_attr($row['service_code']??'').'"><input type="hidden" data-delete-flag name="'.esc_attr($prefix).'[_delete]" value="'.($deleted?'1':'0').'"></td><td>';
        self::control($prefix,'name',$row,'Название услуги','textarea');
        echo '<span class="s101-delete-message" data-delete-message'.($deleted?'':' hidden').'>Помечено к удалению</span>';
        echo '<details class="s101-advanced"><summary>Описание и дополнительные настройки</summary><label>Описание услуги'; self::control($prefix,'description',$row,'Описание услуги','textarea'); echo '</label><p>Название общего кода услуги используется у нескольких моделей. Цена, срок и пометка относятся к этой модели.</p><label>Тип цены работы'; self::control($prefix,'work_type',$row,'Тип цены работы','select',['Фиксированная','От','Бесплатно','По запросу']); echo '</label><label>Работа без детали, ₽'; self::control($prefix,'work_amount',$row,'Работа без детали','number'); echo '</label><label>Показ услуги'; self::control($prefix,'action',$row,'Показ услуги','select',['Обновить','Скрыть']); echo '</label></details></td><td>';
        self::control($prefix,'total_type',$row,'Тип цены с деталью','select',['Фиксированная','Ориентир','От','Бесплатно','После диагностики']);
        self::control($prefix,'total_amount',$row,'Цена с деталью','number'); echo '</td><td>';
        self::control($prefix,'time',$row,'Срок ремонта','textarea'); echo '</td><td>';
        self::control($prefix,'badge',$row,'Пометка','textarea'); echo '</td><td class="s101-order-col">';
        self::control($prefix,'order',$row,'Порядок','number'); echo '</td></tr>';
    }

    private static function control(string $prefix,string $key,array $row,string $label,string $type,array $choices=[]): void
    {
        $defaults=['work_type'=>'По запросу','total_type'=>'После диагностики','action'=>'Обновить','order'=>'100'];
        $value=(string)($row[$key]??$defaults[$key]??''); $name=$prefix.'['.$key.']';
        $attributes=' name="'.esc_attr($name).'" aria-label="'.esc_attr($label).'" autocomplete="off"';
        if (in_array($key,['work_type','total_type'],true)) { $attributes.=' data-price-type="'.(str_starts_with($key,'work_')?'work':'total').'"'; }
        if (in_array($key,['work_amount','total_amount'],true)) { $attributes.=' data-price-amount="'.(str_starts_with($key,'work_')?'work':'total').'"'; }
        if ($type==='select') {
            echo '<select'.$attributes.'>';
            foreach ($choices as $choice) { echo '<option'.selected($value,$choice,false).'>'.esc_html($choice).'</option>'; }
            echo '</select>';
        } elseif ($type==='textarea') {
            echo '<textarea rows="2"'.$attributes.' maxlength="'.($key==='name'?'240':'2000').'"'.($key==='name'?' data-service-name':'').'>'.esc_textarea($value).'</textarea>';
        } else {
            echo '<input type="number" min="0" step="'.($key==='order'?'1':'0.01').'"'.($key==='order'?' max="99999"':' inputmode="decimal" max="99999999.99"').$attributes.' value="'.esc_attr($value).'">';
        }
    }
}
