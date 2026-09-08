<?php
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;

final class Import
{
    public static function preview(array $input): array
    {
        $devices=Catalog::devices(true); $prices=Catalog::prices(); $revision=Catalog::revision();
        $plan=['devices'=>[], 'prices'=>[], 'errors'=>[], 'warnings'=>[], 'counts'=>['new'=>0,'changed'=>0,'hidden'=>0,'same'=>0], 'revision'=>$revision];
        if (($input['revision']??null)!==null && $input['revision']!==$revision) { $plan['errors'][]='Каталог изменился после экспорта. Скачайте свежую книгу и повторите правки.'; }
        $seen=[]; $paths=[]; $names=[];
        foreach ($devices as $code=>$device) { $paths[$device['path']]=$code; }
        foreach ($input['devices'] as $row) {
            try {
                $code=self::code($row['code']??'');
                if (isset($seen[$code])) { throw new \InvalidArgumentException('Повторяется код устройства '.$code.'.'); }
                $seen[$code]=true; $before=$devices[$code]??null;
                $after=self::device($row,$before);
                if (isset($paths[$after['path']]) && $paths[$after['path']]!==$code) { throw new \InvalidArgumentException('Этот адрес уже принадлежит другому устройству.'); }
                if ($after['publication']==='Опубликовать' && !current_user_can('publish_s101_devices')) { throw new \InvalidArgumentException('Недостаточно прав для публикации.'); }
                $paths[$after['path']]=$code; $devices[$code]=$after;
                self::change($plan,'devices',$code,$before,$after,$row);
            } catch (\Throwable $error) { $plan['errors'][]=self::location($row).$error->getMessage(); }
        }
        $seen=[];
        foreach ($input['prices'] as $row) {
            try {
                if (!current_user_can('manage_s101_prices')) { throw new \InvalidArgumentException('Недостаточно прав для изменения цен.'); }
                $device=self::code($row['device_code']??''); $service=self::code($row['service_code']??''); $key=$device.'|'.$service;
                if (!isset($devices[$device])) { throw new \InvalidArgumentException('Неизвестное устройство '.$device.'.'); }
                if (isset($seen[$key])) { throw new \InvalidArgumentException('Повторяется пара устройства и услуги '.$key.'.'); }
                $seen[$key]=true; $before=$prices[$key]??null; $after=self::price($row,$before);
                if (isset($names[$service]) && $names[$service]!==$after['name']) { throw new \InvalidArgumentException('Для кода '.$service.' указаны разные названия услуги.'); }
                $names[$service]=$after['name'];
                self::change($plan,'prices',$key,$before,$after,$row);
            } catch (\Throwable $error) { $plan['errors'][]=self::location($row).$error->getMessage(); }
        }
        if (!$input['devices'] && !$input['prices']) { $plan['errors'][]='В книге нет строк с данными.'; }
        return $plan;
    }

    private static function location(array $row): string { return ($row['_sheet']??'Форма').', строка '.($row['_row']??1).': '; }
    public static function code(string $code): string
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D',$code)) { throw new \InvalidArgumentException('Код обязателен: латинские буквы, цифры, дефис или подчёркивание, до 64 символов.'); }
        return $code;
    }
    private static function merge(array $row, ?array $before, array $keys): array
    {
        $data=$before??[];
        foreach ($keys as $key) {
            $value=trim((string)($row[$key]??''));
            if ($value==='[очистить]') { $data[$key]=''; }
            elseif ($value!=='' || !$before) { $data[$key]=sanitize_textarea_field($value); }
        }
        return $data;
    }
    public static function device(array $row, ?array $before): array
    {
        $data=self::merge($row,$before,Workbook::DEVICE_KEYS);
        foreach (['code','name','category','brand'] as $required) { if (empty($data[$required])) { throw new \InvalidArgumentException('Не заполнено обязательное поле «'.$required.'».'); } }
        $data['publication']=$data['publication']?:'Черновик';
        if (!in_array($data['publication'],['Черновик','Опубликовать','Скрыть'],true)) { throw new \InvalidArgumentException('Неизвестное состояние публикации.'); }
        foreach (['category_slug'=>'category','brand_slug'=>'brand','model_slug'=>'name'] as $key=>$source) {
            $data[$key]=$data[$key]?:Catalog::slug($data[$source]);
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D',$data[$key])) { throw new \InvalidArgumentException('Код раздела/бренда/модели: латинские буквы, цифры и дефисы.'); }
            if ($before && $data[$key]!==$before[$key]) { throw new \InvalidArgumentException('Существующие коды адреса нельзя менять импортом. Название можно менять свободно.'); }
        }
        $data['category_title']=$data['category_title']?:'Ремонт '.mb_strtolower($data['category']);
        $expected='/remont/'.$data['category_slug'].'/'.$data['brand_slug'].'/'.$data['model_slug'].'/';
        $path=$data['path']?:$expected;
        if (str_starts_with($path,'https://')) { $path=(string)wp_parse_url($path,PHP_URL_PATH); }
        $path='/'.ltrim(preg_replace('~index\.html$~','',$path),'/'); $path=rtrim($path,'/').'/';
        if ($path!==$expected || ($before && $path!==$before['path'])) { throw new \InvalidArgumentException('Адрес должен соответствовать категории, бренду и модели: '.$expected); }
        $data['path']=$path;
        foreach (['image1','image2','image3'] as $key) {
            $url=str_replace(' ','%20',$data[$key]??'');
            $data[$key]=$url;
            if ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) && !preg_match('~^https://[^\s]+$~u',$url))) { throw new \InvalidArgumentException('Нужна полная HTTPS-ссылка на изображение.'); }
            if ($url!=='' && strtolower((string)wp_parse_url($url,PHP_URL_SCHEME))!=='https') { throw new \InvalidArgumentException('Для изображений разрешён только HTTPS.'); }
        }
        return $data;
    }
    public static function price(array $row, ?array $before): array
    {
        $data=self::merge($row,$before,array_values(array_diff(Workbook::PRICE_KEYS,['device_name'])));
        if (empty($data['name'])) { throw new \InvalidArgumentException('Название услуги обязательно.'); }
        if (mb_strlen($data['name'])>240) { throw new \InvalidArgumentException('Название услуги длиннее 240 символов.'); }
        foreach (['work'=>['Фиксированная','От','Бесплатно','По запросу'],'total'=>['Фиксированная','Ориентир','От','Бесплатно','После диагностики']] as $part=>$allowed) {
            $type=$data[$part.'_type']??'';
            if (!in_array($type,$allowed,true)) { throw new \InvalidArgumentException('Выберите тип цены в поле '.$part.'.'); }
            $amount=Catalog::money($data[$part.'_amount']??null);
            if ($type==='Бесплатно') { $amount='0.00'; }
            elseif (in_array($type,['По запросу','После диагностики'],true)) { $amount=null; }
            elseif ($amount===null) { throw new \InvalidArgumentException('Для числового типа цены нужна сумма.'); }
            $data[$part.'_amount']=$amount;
        }
        $data['action']=$data['action']?:'Обновить';
        if (!in_array($data['action'],['Обновить','Скрыть'],true)) { throw new \InvalidArgumentException('Действие: Обновить или Скрыть.'); }
        $order=$data['order']??'';
        if ($order!=='' && !preg_match('/^\d{1,5}$/D',(string)$order)) { throw new \InvalidArgumentException('Порядок — целое число от 0 до 99999.'); }
        $data['order']=$order==='' ? 100 : (int)$order;
        return $data;
    }
    private static function change(array &$plan,string $kind,string $key,?array $before,array $after,array $row): void
    {
        $state=$before===null?'new':($before===$after?'same':(($after['publication']??$after['action']??'')==='Скрыть'?'hidden':'changed'));
        $plan['counts'][$state]++;
        $plan[$kind][$key]=['before'=>$before,'after'=>$after,'state'=>$state,'row'=>$row['_row']??1];
    }
    public static function save_plan(array $plan): int
    {
        global $wpdb;
        Catalog::assert_db($wpdb->insert(Catalog::table('batches'),['author'=>get_current_user_id(),'created'=>current_time('mysql',true),'state'=>'preview','revision'=>$plan['revision'],'plan'=>Catalog::json($plan)]));
        return (int)$wpdb->insert_id;
    }
    public static function batch(int $id): array
    {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Catalog::table('batches').' WHERE id=%d',$id),ARRAY_A);
        if (!$row || ((int)$row['author']!==get_current_user_id() && !current_user_can('manage_options'))) { throw new \RuntimeException('Пакет не найден.'); }
        $row['plan']=json_decode($row['plan'],true); return $row;
    }

    public static function apply(int $id): array
    {
        global $wpdb;
        $batch=self::batch($id); $plan=$batch['plan'];
        if ($batch['state']==='applied') { return $plan; }
        if ($batch['state']!=='preview' || $plan['errors']) { throw new \RuntimeException('Этот пакет нельзя применить. Исправьте ошибки и загрузите книгу снова.'); }
        if ((int)$batch['revision']!==Catalog::revision()) { throw new \RuntimeException('Каталог изменился после проверки. Выполните новую проверку.'); }
        foreach ($plan['devices'] as &$change) {
            foreach (['image1','image2','image3'] as $key) {
                $url=$change['after'][$key]??'';
                if ($url==='') { $change['after'][$key.'_id']=0; continue; }
                if ($url===($change['before'][$key]??null) && !empty($change['after'][$key.'_id'])) { continue; }
                try { $change['after'][$key.'_id']=Images::prepare($url); }
                catch (\Throwable $error) {
                    $plan['warnings'][]=$change['after']['name'].': '.$error->getMessage();
                    $change['after'][$key]=$change['before'][$key]??'';
                    $change['after'][$key.'_id']=$change['before'][$key.'_id']??0;
                }
            }
        }
        unset($change);
        $snapshot=self::snapshot(); $touched=[];
        Catalog::assert_db($wpdb->query('START TRANSACTION'));
        try {
            $revision=(int)$wpdb->get_var('SELECT revision FROM '.Catalog::table('state').' WHERE id=1 FOR UPDATE');
            $state=$wpdb->get_var($wpdb->prepare('SELECT state FROM '.Catalog::table('batches').' WHERE id=%d FOR UPDATE',$id));
            if ($state==='applied') { $wpdb->query('ROLLBACK'); return self::batch($id)['plan']; }
            if ($revision!==(int)$batch['revision'] || $state!=='preview') { throw new \RuntimeException('Данные уже изменились. Повторите проверку.'); }
            foreach ($plan['devices'] as $code=>&$change) {
                $data=$change['after'];
                if ($data['publication']==='Опубликовать' && !current_user_can('publish_s101_devices')) { throw new \RuntimeException('Нет прав для публикации.'); }
                $post=['post_type'=>'s101_device','post_title'=>$data['name'],'post_content'=>$data['description'],'post_name'=>$data['model_slug'],'post_status'=>Catalog::status($data['publication'])];
                if (!empty($data['post_id'])) { $post['ID']=$data['post_id']; }
                $post_id=wp_insert_post(wp_slash($post),true);
                if (is_wp_error($post_id)) { throw new \RuntimeException('Ошибка сохранения устройства: '.$post_id->get_error_message()); }
                $data['post_id']=$post_id; $touched[]=$post_id;
                foreach (['s101_category'=>['category_slug','category'],'s101_brand'=>['brand_slug','brand']] as $taxonomy=>[$slug,$name]) {
                    $term=get_term_by('slug',$data[$slug],$taxonomy);
                    if (!$term) { $created=wp_insert_term($data[$name],$taxonomy,['slug'=>$data[$slug]]); if (is_wp_error($created)) { throw new \RuntimeException('Не удалось сохранить категорию или бренд.'); } $term_id=(int)$created['term_id']; }
                    else { $term_id=$term->term_id; }
                    $assigned=wp_set_object_terms($post_id,[$term_id],$taxonomy);
                    if (is_wp_error($assigned)) { throw new \RuntimeException('Не удалось связать устройство с категорией.'); }
                }
                if (!empty($data['image1_id'])) { set_post_thumbnail($post_id,(int)$data['image1_id']); } else { delete_post_thumbnail($post_id); }
                Catalog::assert_db($wpdb->replace(Catalog::table('devices'),['code'=>$code,'post_id'=>$post_id,'path'=>$data['path'],'data'=>Catalog::json($data)]));
                $change['after']=$data;
            }
            unset($change);
            foreach ($plan['prices'] as $change) {
                if (!current_user_can('manage_s101_prices')) { throw new \RuntimeException('Нет прав для изменения цен.'); }
                $data=$change['after'];
                Catalog::assert_db($wpdb->replace(Catalog::table('services'),['code'=>$data['service_code'],'name'=>$data['name']]));
                Catalog::assert_db($wpdb->replace(Catalog::table('prices'),['device_code'=>$data['device_code'],'service_code'=>$data['service_code'],'data'=>Catalog::json($data)]));
            }
            Catalog::assert_db($wpdb->query('UPDATE '.Catalog::table('state').' SET revision=revision+1 WHERE id=1'));
            Catalog::assert_db($wpdb->update(Catalog::table('batches'),['state'=>'applied','plan'=>Catalog::json($plan),'snapshot'=>Catalog::json($snapshot)],['id'=>$id]));
            Catalog::assert_db($wpdb->query('COMMIT'));
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); wp_cache_flush(); throw $error; }
        foreach ($touched as $post_id) { clean_post_cache($post_id); }
        return $plan;
    }

    private static function snapshot(): array
    {
        global $wpdb;
        $result=[];
        foreach (['devices','services','prices'] as $table) { $result[$table]=$wpdb->get_results('SELECT * FROM '.Catalog::table($table),ARRAY_A); }
        return $result;
    }
    public static function restore(int $id): void
    {
        global $wpdb;
        $batch=self::batch($id);
        if ($batch['state']!=='applied' || !$batch['snapshot']) { throw new \RuntimeException('Этот пакет нельзя восстановить.'); }
        Catalog::assert_db($wpdb->query('START TRANSACTION'));
        try {
            $revision=(int)$wpdb->get_var('SELECT revision FROM '.Catalog::table('state').' WHERE id=1 FOR UPDATE');
            if ($revision!==(int)$batch['revision']+1) { throw new \RuntimeException('После этого пакета каталог изменялся. Автоматический откат заблокирован, чтобы сохранить новые правки.'); }
            $old=json_decode($batch['snapshot'],true); $old_ids=array_map('intval',array_column($old['devices'],'post_id'));
            foreach (Catalog::devices(true) as $device) { if (!in_array($device['post_id'],$old_ids,true)) { wp_delete_post($device['post_id'],true); } }
            foreach ($old['devices'] as $row) {
                $data=json_decode($row['data'],true);
                $saved=wp_update_post(wp_slash(['ID'=>(int)$row['post_id'],'post_title'=>$data['name'],'post_content'=>$data['description'],'post_status'=>Catalog::status($data['publication'])]),true);
                if (is_wp_error($saved)) { throw new \RuntimeException('Не удалось восстановить устройство.'); }
                if (!empty($data['image1_id'])) { set_post_thumbnail((int)$row['post_id'],(int)$data['image1_id']); } else { delete_post_thumbnail((int)$row['post_id']); }
            }
            foreach (['devices','services','prices'] as $table) {
                Catalog::assert_db($wpdb->query('DELETE FROM '.Catalog::table($table)));
                foreach ($old[$table] as $row) { Catalog::assert_db($wpdb->insert(Catalog::table($table),$row)); }
            }
            Catalog::assert_db($wpdb->query('UPDATE '.Catalog::table('state').' SET revision=revision+1 WHERE id=1'));
            Catalog::assert_db($wpdb->update(Catalog::table('batches'),['state'=>'restored'],['id'=>$id]));
            Catalog::assert_db($wpdb->query('COMMIT'));
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); wp_cache_flush(); throw $error; }
        wp_cache_flush();
    }
}

require_once __DIR__.'/images.php';
