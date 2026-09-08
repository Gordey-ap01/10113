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

    public static function devices(bool $include_hidden = false): array
    {
        global $wpdb;
        $sql = 'SELECT d.*, p.post_status, p.post_title FROM ' . self::table('devices') . " d JOIN {$wpdb->posts} p ON p.ID=d.post_id";
        if (!$include_hidden) { $sql .= " WHERE p.post_status='publish'"; }
        $rows = $wpdb->get_results($sql . ' ORDER BY d.post_id', ARRAY_A);
        $result = [];
        foreach ($rows as $row) { $data = json_decode($row['data'], true); $data['post_id']=(int)$row['post_id']; $data['path']=$row['path']; $result[$row['code']]=$data; }
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
