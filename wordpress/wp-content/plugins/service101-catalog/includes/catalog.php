<?php
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;

final class Catalog
{
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 's101_' . $name; }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $schemas = [
            'devices' => "code varchar(64) NOT NULL, post_id bigint unsigned NOT NULL, path varchar(240) NOT NULL, data longtext NOT NULL, PRIMARY KEY (code), UNIQUE KEY post_id (post_id), UNIQUE KEY path (path)",
            'services' => "code varchar(64) NOT NULL, name varchar(240) NOT NULL, PRIMARY KEY (code)",
            'prices' => "device_code varchar(64) NOT NULL, service_code varchar(64) NOT NULL, data longtext NOT NULL, PRIMARY KEY (device_code,service_code)",
            'batches' => "id bigint unsigned NOT NULL AUTO_INCREMENT, author bigint unsigned NOT NULL, created datetime NOT NULL, state varchar(20) NOT NULL, revision bigint unsigned NOT NULL, plan longtext NOT NULL, snapshot longtext NULL, PRIMARY KEY (id)",
            'state' => "id tinyint unsigned NOT NULL, revision bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (id)",
        ];
        foreach ($schemas as $name => $schema) {
            $lines = str_replace(', ', ",\n", $schema);
            dbDelta('CREATE TABLE ' . self::table($name) . " (\n$lines\n) ENGINE=InnoDB $charset;");
        }
        $wpdb->query('INSERT IGNORE INTO ' . self::table('state') . ' (id, revision) VALUES (1,0)');
        foreach (['administrator'] as $role_name) {
            $role = get_role($role_name);
            foreach (['manage_s101_catalog','import_s101_catalog','publish_s101_devices','manage_s101_prices'] as $cap) { $role?->add_cap($cap); }
        }
        Routes::register();
        flush_rewrite_rules(false);
    }

    public static function revision(): int { global $wpdb; return (int)$wpdb->get_var('SELECT revision FROM ' . self::table('state') . ' WHERE id=1'); }

    /** Native WordPress terms are the single directory for categories and brands. */
    public static function terms(string $taxonomy): array
    {
        if (!in_array($taxonomy,['s101_category','s101_brand'],true)) { throw new \InvalidArgumentException('Неизвестный справочник.'); }
        $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
        if (is_wp_error($terms)) { throw new \RuntimeException('Не удалось прочитать справочник.'); }
        return $terms;
    }

    public static function term_by_slug(string $taxonomy,string $slug): ?\WP_Term
    {
        $term=get_term_by('slug',$slug,$taxonomy);
        return $term instanceof \WP_Term ? $term : null;
    }

    /** Creates a term or updates its editor-owned presentation settings. Slugs stay stable after creation. */
    public static function save_term(string $taxonomy,array $input): \WP_Term
    {
        global $wpdb;
        if (!in_array($taxonomy,['s101_category','s101_brand'],true)) { throw new \InvalidArgumentException('Неизвестный справочник.'); }
        $id=absint($input['term_id']??0); $name=sanitize_text_field((string)($input['name']??''));
        if ($name==='') { throw new \InvalidArgumentException('Введите название.'); }
        if (mb_strlen($name)>120) { throw new \InvalidArgumentException('Название длиннее 120 символов.'); }
        $categories=[]; $orders=[];
        if ($taxonomy==='s101_brand') {
            if (!is_array($input['category_slugs']??null) || !is_array($input['category_orders']??[])) { throw new \InvalidArgumentException('Выберите категории бренда.'); }
            foreach ($input['category_slugs'] as $slug) {
                if (!is_string($slug) || !self::term_by_slug('s101_category',$slug)) { throw new \InvalidArgumentException('Выбрана неизвестная категория.'); }
                $categories[]=$slug;
                $orders[$slug]=self::weight($input['category_orders'][$slug]??'',1000);
            }
            $categories=array_values(array_unique($categories));
            if (!$categories) { throw new \InvalidArgumentException('Выберите хотя бы одну категорию бренда.'); }
        }
        if (array_key_exists('sort_order',$input)) { $input['sort_order']=self::weight($input['sort_order'],1000); }
        self::assert_db($wpdb->query('START TRANSACTION'));
        try {
        $revision=(int)$wpdb->get_var('SELECT revision FROM '.self::table('state').' WHERE id=1 FOR UPDATE');
        if (isset($input['revision']) && (int)$input['revision']!==$revision) { throw new \RuntimeException('Каталог изменился. Обновите страницу справочника и повторите правки.'); }
        if ($id) {
            $term=get_term($id,$taxonomy);
            if (!$term instanceof \WP_Term) { throw new \InvalidArgumentException('Элемент справочника не найден.'); }
            if ($taxonomy==='s101_brand') {
                foreach (self::brand_usage()[$term->slug]??[] as $slug) {
                    if (!in_array($slug,$categories,true)) { throw new \InvalidArgumentException('В категории уже есть модели этого бренда. Сохраните её связь с брендом.'); }
                }
            }
            $saved=wp_update_term($id,$taxonomy,['name'=>$name]);
        } else {
            $requested_slug=trim((string)($input['slug']??''));
            $slug=self::slug($requested_slug!==''?$requested_slug:$name);
            if ($slug==='' || self::term_by_slug($taxonomy,$slug)) { throw new \InvalidArgumentException('Такой постоянный адрес уже занят.'); }
            $saved=wp_insert_term($name,$taxonomy,['slug'=>$slug]);
        }
        if (is_wp_error($saved)) { throw new \RuntimeException($saved->get_error_message()); }
        $term=get_term((int)$saved['term_id'],$taxonomy);
        if (!$term instanceof \WP_Term) { throw new \RuntimeException('Не удалось сохранить справочник.'); }
        foreach (['sort_order'=>'absint','home_enabled'=>static fn($v)=>$v==='1'?'1':'0','home_image_id'=>'absint','catalog_title'=>'sanitize_text_field','catalog_subtitle'=>'sanitize_text_field','catalog_intro'=>'sanitize_textarea_field','info_title'=>'sanitize_text_field','info_text'=>'sanitize_textarea_field','info_items'=>'sanitize_textarea_field'] as $key=>$sanitize) {
            if (array_key_exists($key,$input)) { update_term_meta($term->term_id,'s101_'.$key,$sanitize($input[$key])); }
        }
        if ($taxonomy==='s101_brand') {
            update_term_meta($term->term_id,'s101_category_slugs',$categories);
            update_term_meta($term->term_id,'s101_category_orders',$orders);
        }
        self::assert_db($wpdb->query('UPDATE '.self::table('state').' SET revision=revision+1 WHERE id=1'));
        self::assert_db($wpdb->query('COMMIT'));
        return $term;
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); wp_cache_flush(); throw $error; }
    }

    public static function weight(mixed $value,int $default=1000): int
    {
        if ($value==='' || $value===null) { return $default; }
        if (!is_scalar($value) || !preg_match('/^\d{1,5}$/D',(string)$value)) { throw new \InvalidArgumentException('Порядок — целое число от 0 до 99999. Меньше число — выше в списке.'); }
        return (int)$value;
    }

    public static function brand_usage(?array $devices=null): array
    {
        $usage=[];
        foreach ($devices??self::devices(true) as $device) { $usage[$device['brand_slug']][$device['category_slug']]=true; }
        return array_map('array_keys',$usage);
    }

    /** Existing usage is always a relation, including devices imported before brand categories existed. */
    public static function brand_categories(\WP_Term $brand,?array $usage=null): array
    {
        $saved=get_term_meta($brand->term_id,'s101_category_slugs',true);
        $usage??=self::brand_usage();
        return array_values(array_unique(array_merge(is_array($saved)?$saved:[],$usage[$brand->slug]??[])));
    }

    public static function brand_order(\WP_Term $brand,string $category_slug): int
    {
        $orders=get_term_meta($brand->term_id,'s101_category_orders',true);
        return is_array($orders) && isset($orders[$category_slug]) ? (int)$orders[$category_slug] : 1000;
    }

    public static function model_counts(string $taxonomy): array
    {
        $key=$taxonomy==='s101_category'?'category_slug':'brand_slug'; $counts=[];
        foreach (self::devices(true) as $device) {
            $slug=$device[$key]; $counts[$slug]??=['total'=>0,'published'=>0];
            $counts[$slug]['total']++;
            if ($device['publication']==='Опубликовать') { $counts[$slug]['published']++; }
        }
        return $counts;
    }

    /** Permanently removes one device and all of its price rows. Media-library files are kept. */
    public static function delete_device(string $code,int $expected_revision): array
    {
        global $wpdb;
        $code=strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/D',$code)) { throw new \InvalidArgumentException('Некорректный код устройства.'); }
        self::assert_db($wpdb->query('START TRANSACTION'));
        try {
            self::lock_revision($expected_revision);
            $row=$wpdb->get_row($wpdb->prepare('SELECT code,post_id FROM '.self::table('devices').' WHERE code=%s FOR UPDATE',$code),ARRAY_A);
            if (!$row) { throw new \InvalidArgumentException('Устройство уже удалено или не найдено.'); }
            $service_codes=self::delete_device_rows([$row]);
            self::finish_delete_transaction($service_codes);
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); wp_cache_flush(); throw $error; }
        wp_cache_flush();
        return ['devices'=>1];
    }

    /** Permanently removes a category/brand and every device that belongs to it. */
    public static function delete_term(string $taxonomy,int $term_id,int $expected_revision): array
    {
        global $wpdb;
        if (!in_array($taxonomy,['s101_category','s101_brand'],true)) { throw new \InvalidArgumentException('Неизвестный справочник.'); }
        $term=get_term($term_id,$taxonomy);
        if (!$term instanceof \WP_Term) { throw new \InvalidArgumentException('Категория или бренд уже удалены.'); }
        $key=$taxonomy==='s101_category'?'category_slug':'brand_slug';
        self::assert_db($wpdb->query('START TRANSACTION'));
        try {
            self::lock_revision($expected_revision);
            $rows=[];
            foreach ($wpdb->get_results('SELECT code,post_id,data FROM '.self::table('devices').' FOR UPDATE',ARRAY_A) as $row) {
                $data=json_decode((string)$row['data'],true);
                if (is_array($data) && ($data[$key]??null)===$term->slug) { $rows[]=$row; }
            }
            $service_codes=self::delete_device_rows($rows);
            if ($taxonomy==='s101_category') {
                foreach (self::terms('s101_brand') as $brand) {
                    $categories=get_term_meta($brand->term_id,'s101_category_slugs',true);
                    $orders=get_term_meta($brand->term_id,'s101_category_orders',true);
                    if (is_array($categories)) { update_term_meta($brand->term_id,'s101_category_slugs',array_values(array_diff($categories,[$term->slug]))); }
                    if (is_array($orders) && array_key_exists($term->slug,$orders)) { unset($orders[$term->slug]); update_term_meta($brand->term_id,'s101_category_orders',$orders); }
                }
            }
            $deleted=wp_delete_term($term_id,$taxonomy);
            if (is_wp_error($deleted) || $deleted===false) { throw new \RuntimeException(is_wp_error($deleted)?$deleted->get_error_message():'Не удалось удалить категорию или бренд.'); }
            self::finish_delete_transaction($service_codes);
        } catch (\Throwable $error) { $wpdb->query('ROLLBACK'); wp_cache_flush(); throw $error; }
        wp_cache_flush();
        return ['devices'=>count($rows)];
    }

    private static function lock_revision(int $expected_revision): void
    {
        global $wpdb;
        $revision=(int)$wpdb->get_var('SELECT revision FROM '.self::table('state').' WHERE id=1 FOR UPDATE');
        if ($revision!==$expected_revision) { throw new \RuntimeException('Каталог изменился. Обновите страницу и повторите удаление.'); }
    }

    private static function delete_device_rows(array $rows): array
    {
        global $wpdb; $service_codes=[];
        foreach ($rows as $row) {
            $code=(string)$row['code']; $post_id=(int)$row['post_id'];
            $service_codes=array_merge($service_codes,$wpdb->get_col($wpdb->prepare('SELECT service_code FROM '.self::table('prices').' WHERE device_code=%s',$code)));
            self::assert_db($wpdb->delete(self::table('prices'),['device_code'=>$code],['%s']));
            self::assert_db($wpdb->delete(self::table('devices'),['code'=>$code],['%s']));
            if ($post_id && !wp_delete_post($post_id,true)) { throw new \RuntimeException('Не удалось полностью удалить запись устройства.'); }
        }
        return array_values(array_unique(array_map('strval',$service_codes)));
    }

    private static function finish_delete_transaction(array $service_codes): void
    {
        global $wpdb;
        foreach ($service_codes as $service_code) {
            self::assert_db($wpdb->query($wpdb->prepare('DELETE FROM '.self::table('services').' WHERE code=%s AND NOT EXISTS (SELECT 1 FROM '.self::table('prices').' WHERE service_code=%s)',$service_code,$service_code)));
        }
        self::assert_db($wpdb->query('UPDATE '.self::table('state').' SET revision=revision+1 WHERE id=1'));
        self::assert_db($wpdb->query('COMMIT'));
    }

    /** Resolve admin selections on the server; submitted display names and slugs are not trusted. */
    public static function selected_device_terms(array $input,?array $before=null): array
    {
        foreach (['category_slug','brand_slug'] as $key) { if (!is_string($input[$key]??null)) { throw new \InvalidArgumentException('Выберите категорию и бренд из существующих справочников.'); } }
        $category=self::term_by_slug('s101_category',(string)($input['category_slug']??''));
        $brand=self::term_by_slug('s101_brand',(string)($input['brand_slug']??''));
        if (!$category || !$brand) { throw new \InvalidArgumentException('Выберите категорию и бренд из существующих справочников.'); }
        if (!in_array($category->slug,self::brand_categories($brand),true)) { throw new \InvalidArgumentException('Этот бренд не относится к выбранной категории. Сначала измените категории бренда.'); }
        $input['category']=$category->name; $input['brand']=$brand->name;
        return $input;
    }

    public static function category(string $slug): array
    {
        $term=self::term_by_slug('s101_category',$slug);
        $fallback=['title'=>'Ремонт техники','subtitle'=>'Услуги и цены','intro'=>'Выберите модель, чтобы увидеть актуальные услуги и цены.','info_title'=>'Перед ремонтом','info_text'=>'Мастер уточнит неисправность и согласует работы до начала ремонта.','info_items'=>['Диагностика перед сложным ремонтом','Стоимость подтверждаем до начала работ'],'home_enabled'=>false,'home_image_id'=>0,'sort_order'=>1000];
        if (!$term) { return $fallback+['slug'=>$slug,'name'=>$slug]; }
        $copy=function_exists('s101_copy')?s101_copy():[];
        $legacy=$copy['categories'][$slug]??[]; $legacy_info=$copy['info'][$slug]??[];
        $value=static function(string $key,mixed $default='') use($term): mixed {
            $value=get_term_meta($term->term_id,'s101_'.$key,true);
            return $value!=='' ? $value : $default;
        };
        $items=preg_split('/\R/u',(string)$value('info_items',''));
        $items=array_values(array_filter(array_map('trim',$items)));
        return ['term_id'=>(int)$term->term_id,'slug'=>$term->slug,'name'=>$term->name,
            'title'=>$value('catalog_title',$legacy['title']??$term->name),'subtitle'=>$value('catalog_subtitle',$legacy['subtitle']??$fallback['subtitle']),
            'intro'=>$value('catalog_intro',$legacy['intro']??$fallback['intro']),'info_title'=>$value('info_title',$legacy_info['title']??$fallback['info_title']),
            'info_text'=>$value('info_text',$legacy_info['text']??$fallback['info_text']),'info_items'=>$items?:($legacy_info['items']??$fallback['info_items']),
            'home_enabled'=>$value('home_enabled',$legacy?'1':'0')==='1','home_image_id'=>(int)$value('home_image_id',0),'sort_order'=>(int)$value('sort_order',1000)];
    }

    public static function categories(bool $include_hidden=false): array
    {
        $device_categories=[];
        foreach (self::devices($include_hidden) as $device) { $device_categories[$device['category_slug']]=true; }
        $result=[];
        foreach (self::terms('s101_category') as $term) { if (isset($device_categories[$term->slug])) { $result[$term->slug]=self::category($term->slug); } }
        uasort($result,static fn($a,$b)=>($a['sort_order']<=>$b['sort_order']) ?: strnatcasecmp($a['title'],$b['title']));
        return $result;
    }

    public static function devices(bool $include_hidden = false): array
    {
        global $wpdb;
        $sql = 'SELECT d.*, p.post_status, p.post_title FROM ' . self::table('devices') . " d JOIN {$wpdb->posts} p ON p.ID=d.post_id";
        $sql .= $include_hidden ? " WHERE p.post_status IN ('publish','draft','private','pending','future')" : " WHERE p.post_status='publish'";
        $rows = $wpdb->get_results($sql . ' ORDER BY d.post_id', ARRAY_A);
        $result = []; $categories=[]; $brands=[]; $category_rank=[]; $brand_rank=[];
        foreach (self::terms('s101_category') as $term) { $categories[$term->slug]=$term; }
        foreach (self::terms('s101_brand') as $term) { $brands[$term->slug]=$term; }
        foreach ($rows as $row) {
            $data=json_decode($row['data'],true); $data['post_id']=(int)$row['post_id']; $data['path']=$row['path'];
            $data['publication']=self::label($row['post_status']); $data['model_order']=(int)($data['model_order']??1000);
            $category=$data['category_slug']; $brand=$data['brand_slug'];
            if (isset($categories[$category])) { $data['category']=$categories[$category]->name; }
            if (isset($brands[$brand])) { $data['brand']=$brands[$brand]->name; }
            $category_rank[$category]??=count($category_rank);
            $brand_rank[$category][$brand]??=count($brand_rank[$category]??[]);
            $result[$row['code']]=$data;
        }
        uasort($result,static function(array $a,array $b) use($categories,$brands,$category_rank,$brand_rank): int {
            $category_weight=static function(string $slug) use($categories): int {
                $term=$categories[$slug]??null;
                return $term && metadata_exists('term',$term->term_id,'s101_sort_order')?(int)get_term_meta($term->term_id,'s101_sort_order',true):1000;
            };
            $a_category=$a['category_slug']; $b_category=$b['category_slug'];
            return ($category_weight($a_category)<=>$category_weight($b_category))
                ?: ($category_rank[$a_category]<=>$category_rank[$b_category])
                ?: ((isset($brands[$a['brand_slug']])?self::brand_order($brands[$a['brand_slug']],$a_category):1000)<=>(isset($brands[$b['brand_slug']])?self::brand_order($brands[$b['brand_slug']],$b_category):1000))
                ?: ($brand_rank[$a_category][$a['brand_slug']]<=>$brand_rank[$b_category][$b['brand_slug']])
                ?: ($a['model_order']<=>$b['model_order'])
                ?: ($a['post_id']<=>$b['post_id']);
        });
        return $result;
    }

    public static function prices(?string $code = null, bool $include_hidden = true): array
    {
        global $wpdb;
        $sql='SELECT p.*, s.name FROM '.self::table('prices').' p JOIN '.self::table('services').' s ON s.code=p.service_code';
        if ($code !== null) { $sql .= $wpdb->prepare(' WHERE p.device_code=%s', $code); }
        $result=[];
        foreach ($wpdb->get_results($sql, ARRAY_A) as $row) {
            $data=json_decode($row['data'],true); $data['name']=$row['name'];
            if ($include_hidden || $data['action']!=='Скрыть') { $result[$row['device_code'].'|'.$row['service_code']]=$data; }
        }
        uasort($result, static fn($a,$b)=>($a['order']<=>$b['order']) ?: strcmp($a['service_code'],$b['service_code']));
        return $result;
    }

    public static function status(string $label): string { return ['Черновик'=>'draft','Опубликовать'=>'publish','Скрыть'=>'private'][$label] ?? 'draft'; }
    public static function label(string $status): string { return ['draft'=>'Черновик','publish'=>'Опубликовать','private'=>'Скрыть'][$status] ?? 'Черновик'; }
    public static function json(array $value): string { return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }

    public static function slug(string $text): string
    {
        $letters=explode(' ', 'а б в г д е ё ж з и й к л м н о п р с т у ф х ц ч ш щ ъ ы ь э ю я');
        $latin=explode(' ', 'a b v g d e yo zh z i y k l m n o p r s t u f kh ts ch sh sch - y - e yu ya');
        return sanitize_title(strtr(mb_strtolower($text), array_combine($letters,$latin)));
    }

    public static function money(mixed $value): ?string
    {
        if ($value==='' || $value===null) { return null; }
        $text=str_replace(["\xc2\xa0",' ', ','],['','','.'],(string)$value);
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$text)) { throw new \InvalidArgumentException('Нужна неотрицательная сумма, максимум 2 знака после запятой.'); }
        [$whole,$fraction]=array_pad(explode('.',$text),2,'');
        return (string)(int)$whole . '.' . str_pad($fraction,2,'0');
    }

    public static function price_text(string $type, ?string $amount): string
    {
        if ($type==='Бесплатно') { return 'Бесплатно'; }
        if (in_array($type,['По запросу','После диагностики'],true)) { return $type; }
        if ($amount===null) { return ''; }
        [$whole,$fraction]=explode('.', $amount);
        $formatted=preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) . ($fraction!=='00' ? ','.$fraction : '') . ' ₽';
        return ($type==='От' ? 'от ' : ($type==='Ориентир' ? '≈ ' : '')) . $formatted;
    }

    public static function assert_db(mixed $result): void
    {
        if ($result===false) { throw new \RuntimeException('Не удалось сохранить данные. Изменения отменены.'); }
    }
}
