<?php
declare(strict_types=1);
namespace Service101;
defined('ABSPATH') || exit;

final class Images
{
    public static function prepare(string $url): int
    {
        global $wpdb;
        if (wp_parse_url($url,PHP_URL_HOST)===wp_parse_url(home_url(),PHP_URL_HOST)) {
            $local_id=attachment_url_to_postid($url);
            if ($local_id && is_file(get_attached_file($local_id)) && wp_attachment_is_image($local_id)) { return $local_id; }
        }
        $key=hash('sha256',$url);
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_s101_image_source' AND meta_value=%s LIMIT 1",$key));
        if ($existing && is_file(get_attached_file($existing))) { return $existing; }
        require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php';
        $path=rawurldecode((string)wp_parse_url($url,PHP_URL_PATH));
        $filename=basename($path);
        $originals=['Телефон.png','Ноутбук.png','Компьютер.png','Приставка.png','Видеокарта.png','Джойстик.png'];
        $legacy=in_array($filename,$originals,true) && str_contains($path,'Картинки устройств/') && in_array(wp_parse_url($url,PHP_URL_HOST),['xn--101-eddot8cge.xn--p1ai','сервис101.рф'],true);
        $temp=wp_tempnam('s101-image');
        if (!$temp) { throw new \RuntimeException('Не удалось подготовить фотографию.'); }
        try {
            if ($legacy && is_file(PLUGIN_DIR.'/originals/'.$filename)) { copy(PLUGIN_DIR.'/originals/'.$filename,$temp); }
            else {
                self::validate_url($url);
                // WordPress validates the destination again at every redirect; only standard HTTPS is accepted here.
                $response=wp_safe_remote_get($url,['timeout'=>12,'redirection'=>0,'stream'=>true,'filename'=>$temp,'limit_response_size'=>8*1024*1024+1]);
                for ($redirect=0;!is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response),[301,302,303,307,308],true) && $redirect<3;$redirect++) {
                    $url=\WP_Http::make_absolute_url(wp_remote_retrieve_header($response,'location'),$url);
                    self::validate_url($url);
                    $response=wp_safe_remote_get($url,['timeout'=>12,'redirection'=>0,'stream'=>true,'filename'=>$temp,'limit_response_size'=>8*1024*1024+1]);
                }
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) { throw new \RuntimeException('Фотография недоступна; прежнее изображение сохранено.'); }
            }
            $size=filesize($temp); $info=@getimagesize($temp);
            if (!$info || $size>8*1024*1024 || $info[0]*$info[1]>30000000 || !in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)) { throw new \RuntimeException('Фото должно быть JPG, PNG или WebP, до 8 МБ и 30 млн пикселей.'); }
            $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$info['mime']];
            $id=media_handle_sideload(['name'=>'device-'.substr($key,0,16).'.'.$extension,'tmp_name'=>$temp],0,null,['post_title'=>pathinfo($filename,PATHINFO_FILENAME)]);
            if (is_wp_error($id)) { throw new \RuntimeException('Не удалось сохранить фотографию в медиатеку.'); }
            update_post_meta($id,'_s101_image_source',$key); return $id;
        } finally { if (is_file($temp)) { wp_delete_file($temp); } }
    }

    private static function validate_url(string $url): void
    {
        $parts=wp_parse_url($url);
        if (!$parts || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port']!==443)) { throw new \RuntimeException('Для фото нужна обычная HTTPS-ссылка без логина и нестандартного порта.'); }
        $host=$parts['host']??'';
        if (!preg_match('/^[a-z0-9.-]+$/iD',$host) || !str_contains($host,'.') || filter_var($host,FILTER_VALIDATE_IP)) { throw new \RuntimeException('Недопустимый адрес изображения.'); }
        $addresses=gethostbynamel($host)?:[];
        foreach (dns_get_record($host,DNS_AAAA)?:[] as $record) { if (isset($record['ipv6'])) { $addresses[]=$record['ipv6']; } }
        if (!$addresses) { throw new \RuntimeException('Адрес фотографии не найден.'); }
        foreach ($addresses as $ip) { if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) { throw new \RuntimeException('Внутренние адреса изображений запрещены.'); } }
        if (!wp_http_validate_url($url)) { throw new \RuntimeException('Адрес фотографии отклонён проверкой WordPress.'); }
    }
}
