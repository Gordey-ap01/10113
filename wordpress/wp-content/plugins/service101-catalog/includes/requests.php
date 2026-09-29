<?php
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;

/** Missing-device enquiries live independently of catalog import/rollback snapshots. */
final class Requests
{
    private const VERSION = '1';
    public const STATUSES = ['new'=>'Новая','in_progress'=>'В работе','done'=>'Обработана'];

    public static function table(): string { global $wpdb; return $wpdb->prefix.'s101_requests'; }

    public static function maybe_upgrade(): void
    {
        if (get_option('s101_requests_schema') === self::VERSION) { return; }
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            request_key char(64) NOT NULL,
            payload_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            category varchar(200) NOT NULL DEFAULT '',
            name varchar(160) NOT NULL,
            phone varchar(20) NOT NULL,
            brand varchar(160) NOT NULL DEFAULT '',
            model varchar(200) NOT NULL DEFAULT '',
            comment text NOT NULL,
            page_url varchar(2048) NOT NULL DEFAULT '',
            status varchar(16) NOT NULL DEFAULT 'new',
            is_test tinyint unsigned NOT NULL DEFAULT 0,
            mail_state varchar(16) NOT NULL DEFAULT 'pending',
            PRIMARY KEY  (id),
            UNIQUE KEY request_key (request_key),
            KEY created_at (created_at),
            KEY source_status_date (is_test,status,created_at)
        ) ENGINE=InnoDB $charset;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))) === $table) {
            update_option('s101_requests_schema',self::VERSION,false);
        }
    }

    private static function text(array $input,string $key,int $limit,bool $multiline=false): string
    {
        $value=$input[$key]??'';
        if (!is_scalar($value)) { throw new \InvalidArgumentException('Некорректное значение поля «'.$key.'».'); }
        $value=$multiline ? sanitize_textarea_field((string)$value) : sanitize_text_field((string)$value);
        if (mb_strlen($value)>$limit) { throw new \InvalidArgumentException('Слишком длинное значение поля «'.$key.'».'); }
        return trim($value);
    }

    public static function phone(mixed $input): string
    {
        if (!is_scalar($input)) { throw new \InvalidArgumentException('Проверьте номер телефона.'); }
        $raw=trim((string)$input);
        if (strlen($raw)>80 || preg_match('/[^\d\s()+\-]/u',$raw)) { throw new \InvalidArgumentException('Проверьте номер телефона.'); }
        $digits=preg_replace('/\D+/','',$raw)??'';
        if (strlen($digits)===10) { $digits='7'.$digits; }
        if (strlen($digits)===11 && $digits[0]==='8') { $digits='7'.substr($digits,1); }
        if (!preg_match('/^7\d{10}$/D',$digits)) { throw new \InvalidArgumentException('Введите номер полностью: +7 и ещё 10 цифр.'); }
        return '+'.$digits;
    }

    /** Input is already wp_unslash()'d by the HTTP boundary. */
    public static function normalize(array $input): array
    {
        $name=self::text($input,'Имя',160);
        if ($name==='') { throw new \InvalidArgumentException('Укажите имя и номер телефона.'); }
        $category=self::text($input,'Категория',200);
        if ($category!=='' && !get_term_by('slug',$category,'s101_category')) {
            throw new \InvalidArgumentException('Обновите страницу и выберите категорию устройства заново.');
        }
        $page=self::text($input,'Страница',2048);
        if ($page!=='') {
            if (str_starts_with($page,'/') && !str_starts_with($page,'//')) { $page=home_url($page); }
            $parts=wp_parse_url($page); $site=wp_parse_url(home_url('/'));
            if (!is_array($parts) || !in_array($parts['scheme']??'',['http','https'],true)
                || strtolower($parts['host']??'')!==strtolower($site['host']??'') || isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException('Некорректный адрес страницы.');
            }
            $page=esc_url_raw($page,['http','https']);
        }
        $request_id=self::text($input,'_request_id',80);
        if ($request_id!=='' && !preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$request_id)) {
            throw new \InvalidArgumentException('Обновите страницу и повторите отправку.');
        }
        return ['name'=>$name,'phone'=>self::phone($input['Телефон']??''),'category'=>$category,
            'brand'=>self::text($input,'Бренд',160),'model'=>self::text($input,'Модель',200),
            'comment'=>self::text($input,'Комментарий',3000,true),'page_url'=>$page,'request_id'=>$request_id];
    }

    public static function create(array $input): array
    {
        global $wpdb;
        $data=self::normalize($input); $request_id=$data['request_id']; unset($data['request_id']);
        $data['is_test']=wp_get_environment_type()==='staging'?1:0;
        $hash=hash_hmac('sha256',(string)wp_json_encode($data),wp_salt('nonce'));
        $key=hash('sha256',home_url('/').'|'.($request_id!==''?$request_id:wp_generate_uuid4()));
        $table=self::table();
        $existing=$wpdb->get_row($wpdb->prepare("SELECT id,payload_hash FROM $table WHERE request_key=%s",$key),ARRAY_A);
        if ($existing) { return self::existing($existing,$hash); }
        $now=current_time('mysql',true);
        $data=array_merge($data,['request_key'=>$key,'payload_hash'=>$hash,'created_at'=>$now,'updated_at'=>$now,
            'status'=>'new','mail_state'=>$data['is_test']?'test':'pending']);
        // A unique request key also protects concurrent retries; never surface database errors containing contact details.
        $suppressed=$wpdb->suppress_errors(true);
        try { $saved=$wpdb->insert($table,$data); }
        finally { $wpdb->suppress_errors($suppressed); }
        if ($saved===false) {
            $existing=$wpdb->get_row($wpdb->prepare("SELECT id,payload_hash FROM $table WHERE request_key=%s",$key),ARRAY_A);
            if ($existing) { return self::existing($existing,$hash); }
            throw new \RuntimeException('Не удалось сохранить заявку. Повторите отправку или позвоните нам.');
        }
        return ['id'=>(int)$wpdb->insert_id,'duplicate'=>false];
    }

    private static function existing(array $existing,string $hash): array
    {
        if (!hash_equals((string)$existing['payload_hash'],$hash)) { throw new \InvalidArgumentException('Эта форма уже отправлена. Обновите страницу для новой заявки.'); }
        return ['id'=>(int)$existing['id'],'duplicate'=>true];
    }

    public static function mail_result(int $id,bool $sent): void
    {
        global $wpdb;
        $wpdb->update(self::table(),['mail_state'=>$sent?'sent':'failed'],['id'=>$id],['%s'],['%d']);
    }

    public static function update_status(int $id,string $status): void
    {
        if (!current_user_can('manage_s101_catalog')) { throw new \RuntimeException('Недостаточно прав.'); }
        if ($id<1 || !isset(self::STATUSES[$status])) { throw new \InvalidArgumentException('Некорректный статус заявки.'); }
        global $wpdb;
        $table=self::table();
        if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d",$id))) { throw new \InvalidArgumentException('Заявка не найдена.'); }
        if ($wpdb->update($table,['status'=>$status,'updated_at'=>current_time('mysql',true)],['id'=>$id],['%s','%s'],['%d'])===false) {
            throw new \RuntimeException('Не удалось обновить статус.');
        }
    }

    public static function menu(): void
    {
        add_submenu_page('s101-catalog','Нет моего устройства — заявки','Нет устройства — заявки','manage_s101_catalog','s101-requests',[self::class,'page']);
    }

    private static function date_value(mixed $value): string
    {
        if (!is_scalar($value)) { return ''; }
        $value=(string)$value; $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,wp_timezone());
        return $date && $date->format('Y-m-d')===$value ? $value : '';
    }

    public static function filters(array $input): array
    {
        $from=self::date_value($input['from']??''); $to=self::date_value($input['to']??'');
        if ($from!=='' && $to!=='' && $from>$to) { [$from,$to]=[$to,$from]; }
        $source=is_scalar($input['source']??null)?(string)$input['source']:(wp_get_environment_type()==='staging'?'test':'live');
        if (!in_array($source,['live','test','all'],true)) { $source=wp_get_environment_type()==='staging'?'test':'live'; }
        $status=is_scalar($input['status']??null)?(string)$input['status']:'';
        if (!isset(self::STATUSES[$status])) { $status=''; }
        $page=is_scalar($input['paged']??null)?max(1,min(1000000,(int)$input['paged'])):1;
        return compact('from','to','source','status','page');
    }

    private static function where(array $filters,bool $include_status=true): array
    {
        $where=['1=1']; $args=[];
        foreach (['from'=>'>=','to'=>'<'] as $field=>$operator) {
            if ($filters[$field]==='') { continue; }
            $date=new \DateTimeImmutable($filters[$field].' 00:00:00',wp_timezone());
            if ($field==='to') { $date=$date->modify('+1 day'); }
            $where[]="created_at $operator %s"; $args[]=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($filters['source']!=='all') { $where[]='is_test=%d'; $args[]=$filters['source']==='test'?1:0; }
        if ($include_status && $filters['status']!=='') { $where[]='status=%s'; $args[]=$filters['status']; }
        return [implode(' AND ',$where),$args];
    }

    /** All analytics and contact rows require the same catalog permission as the UI. */
    public static function report(array $input): array
    {
        if (!current_user_can('manage_s101_catalog')) { throw new \RuntimeException('Недостаточно прав.'); }
        global $wpdb;
        $filters=self::filters($input); [$where,$args]=self::where($filters); $table=self::table();
        $prepare=static fn(string $sql,array $values): string => $values?$wpdb->prepare($sql,$values):$sql;
        $total=(int)$wpdb->get_var($prepare("SELECT COUNT(*) FROM $table WHERE $where",$args));
        $pages=max(1,(int)ceil($total/25)); $filters['page']=min($pages,$filters['page']);
        $rows=$wpdb->get_results($prepare("SELECT * FROM $table WHERE $where ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d",array_merge($args,[25,($filters['page']-1)*25])),ARRAY_A);
        [$summary_where,$summary_args]=self::where($filters,false);
        $counts=['total'=>0,'new'=>0,'in_progress'=>0,'done'=>0];
        foreach ($wpdb->get_results($prepare("SELECT status,COUNT(*) AS quantity FROM $table WHERE $summary_where GROUP BY status",$summary_args),ARRAY_A) as $row) {
            $counts[$row['status']]=(int)$row['quantity']; $counts['total']+=(int)$row['quantity'];
        }
        $top=[];
        foreach (['category','brand','model'] as $field) {
            // Column names are fixed above, never taken from a request.
            $group=$field==='model'?'brand,model':$field;
            $top[$field]=$wpdb->get_results($prepare("SELECT $group,COUNT(*) AS quantity FROM $table WHERE $where GROUP BY $group ORDER BY quantity DESC,$group ASC LIMIT 10",$args),ARRAY_A);
        }
        return compact('filters','total','pages','rows','counts','top');
    }

    public static function action(): void
    {
        if (!current_user_can('manage_s101_catalog')) { wp_die('Недостаточно прав.','Заявки',['response'=>403]); }
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { wp_die('Требуется POST.','Заявки',['response'=>405]); }
        $input=wp_unslash($_POST);
        if (!is_scalar($input['_wpnonce']??null) || !is_scalar($input['request_id']??null) || !is_scalar($input['status']??null)) {
            wp_die('Некорректная форма.','Заявки',['response'=>400]);
        }
        $id=absint($input['request_id']);
        check_admin_referer('s101_request_status_'.$id);
        try { self::update_status($id,(string)$input['status']); }
        catch (\Throwable $error) { wp_die(esc_html($error->getMessage()),'Заявки',['response'=>400]); }
        $input['status']=$input['filter_status']??'';
        $filters=self::filters($input); $filters['paged']=$filters['page']; unset($filters['page']);
        wp_safe_redirect(add_query_arg(array_merge(['page'=>'s101-requests','updated'=>'1'],$filters),admin_url('admin.php'))); exit;
    }

    private static function category_name(string $slug): string
    {
        if ($slug==='') { return 'Не указана'; }
        $term=get_term_by('slug',$slug,'s101_category');
        return $term instanceof \WP_Term?$term->name:$slug;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_s101_catalog')) { wp_die('Недостаточно прав.','Заявки',['response'=>403]); }
        $report=self::report(wp_unslash($_GET)); $filters=$report['filters'];
        echo '<div class="wrap"><h1>Нет моего устройства — заявки</h1><p>Обращения клиентов, которые не нашли устройство в каталоге. Популярные запросы помогут выбрать следующие модели для добавления.</p>';
        if (isset($_GET['updated']) && $_GET['updated']==='1') { echo '<div class="notice notice-success"><p>Статус сохранён.</p></div>'; }
        echo '<form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:20px 0"><input type="hidden" name="page" value="s101-requests">';
        foreach (['from'=>'С даты','to'=>'По дату'] as $field=>$label) {
            echo '<label>'.esc_html($label).'<br><input type="date" name="'.esc_attr($field).'" value="'.esc_attr($filters[$field]).'"></label>';
        }
        echo '<label>Заявки<br><select name="source">';
        foreach (['live'=>'Реальные','test'=>'Тестовые','all'=>'Все'] as $key=>$label) { echo '<option value="'.esc_attr($key).'"'.selected($filters['source'],$key,false).'>'.esc_html($label).'</option>'; }
        echo '</select></label><label>Статус<br><select name="status"><option value="">Все статусы</option>';
        foreach (self::STATUSES as $key=>$label) { echo '<option value="'.esc_attr($key).'"'.selected($filters['status'],$key,false).'>'.esc_html($label).'</option>'; }
        echo '</select></label><button class="button">Показать</button><a class="button" href="'.esc_url(admin_url('admin.php?page=s101-requests')).'">Сбросить</a></form>';
        echo '<p class="description">Даты указаны в часовом поясе сайта. Сводка — за выбранный период и тип заявок; списки популярных запросов учитывают также выбранный статус.</p><div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">';
        foreach (array_merge(['total'=>'Всего за период'],self::STATUSES) as $key=>$label) {
            echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 22px;min-width:130px"><span>'.esc_html($label).'</span><br><strong style="font-size:28px">'.(int)$report['counts'][$key].'</strong></div>';
        }
        echo '</div><div style="display:flex;gap:20px;flex-wrap:wrap;margin:20px 0">';
        foreach (['category'=>'Категории','brand'=>'Частые бренды','model'=>'Частые модели'] as $field=>$title) {
            echo '<div style="flex:1;min-width:240px"><h2>'.esc_html($title).'</h2><table class="widefat striped"><thead><tr><th>Запрос</th><th>Заявок</th></tr></thead><tbody>';
            foreach ($report['top'][$field] as $row) {
                $label=$field==='category'?self::category_name($row[$field]):($row[$field]!==''?$row[$field]:'Не указан'.($field==='model'?'а':''));
                if ($field==='model' && $row['brand']!=='') { $label=$row['brand'].' · '.$label; }
                echo '<tr><td>'.esc_html($label).'</td><td>'.(int)$row['quantity'].'</td></tr>';
            }
            if (!$report['top'][$field]) { echo '<tr><td colspan="2">Пока нет заявок.</td></tr>'; }
            echo '</tbody></table></div>';
        }
        echo '</div><h2>Обращения ('.(int)$report['total'].')</h2><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Дата</th><th>Клиент</th><th>Устройство</th><th>Комментарий и страница</th><th>Статус</th></tr></thead><tbody>';
        foreach ($report['rows'] as $row) {
            $date=get_date_from_gmt($row['created_at'],'d.m.Y H:i');
            echo '<tr><td>'.esc_html($date).'<br><small>№ '.(int)$row['id'].($row['is_test']?' · тестовая':'').'</small></td><td><strong>'.esc_html($row['name']).'</strong><br><a href="'.esc_attr('tel:'.$row['phone']).'">'.esc_html($row['phone']).'</a></td>';
            echo '<td>'.esc_html(self::category_name($row['category'])).'<br>Бренд: '.esc_html($row['brand']?:'не указан').'<br>Модель: '.esc_html($row['model']?:'не указана').'</td>';
            echo '<td style="max-width:360px;overflow-wrap:anywhere">'.nl2br(esc_html($row['comment']?:'Без комментария'));
            if ($row['page_url']!=='') { echo '<br><a href="'.esc_url($row['page_url']).'" target="_blank" rel="noopener noreferrer">Страница обращения</a>'; }
            if ($row['mail_state']==='failed') { echo '<br><small>Письмо не отправлено; заявка сохранена здесь.</small>'; }
            echo '</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('s101_request_status_'.(int)$row['id']);
            foreach (['action'=>'s101_request_status','request_id'=>$row['id'],'from'=>$filters['from'],'to'=>$filters['to'],'source'=>$filters['source'],'paged'=>$filters['page'],'filter_status'=>$filters['status']] as $key=>$value) {
                echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr((string)$value).'">';
            }
            echo '<label class="screen-reader-text" for="s101-status-'.(int)$row['id'].'">Статус заявки</label><select id="s101-status-'.(int)$row['id'].'" name="status">';
            foreach (self::STATUSES as $key=>$label) { echo '<option value="'.esc_attr($key).'"'.selected($row['status'],$key,false).'>'.esc_html($label).'</option>'; }
            echo '</select> <button class="button">Сохранить</button></form></td></tr>';
        }
        if (!$report['rows']) { echo '<tr><td colspan="5">Обращений за выбранный период нет.</td></tr>'; }
        echo '</tbody></table></div>';
        if ($report['pages']>1) {
            $args=$filters; unset($args['page']);
            $base=str_replace('99999999','%#%',add_query_arg(array_merge(['page'=>'s101-requests'],$args,['paged'=>99999999]),admin_url('admin.php')));
            echo '<div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(['base'=>$base,'format'=>'','current'=>$filters['page'],'total'=>$report['pages'],'prev_text'=>'←','next_text'=>'→'])).'</div></div>';
        }
        echo '</div>';
    }
}
