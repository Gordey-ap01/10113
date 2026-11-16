<?php
declare(strict_types=1);
namespace Poisk;

/** Anonymous supplier HTTP sessions. Gordey_ap01. No arbitrary URLs or JS execution. */
interface HttpTransport { public function get(string $url): string; }
final class Http implements HttpTransport
{
    public const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';
    public function __construct(private string $stateDir) {}

    public static function allowed(string $url): bool
    {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        return ($p['scheme'] ?? '') === 'https' && !isset($p['user']) && !isset($p['pass']) && !isset($p['port'])
            && (in_array($host, ['green-spark.ru', 'taggsm.ru', 'moba.ru', 'parts.dvsota.ru'], true)
                || preg_match('/^[a-z-]+\.moba\.ru$/D', $host) === 1);
    }

    public static function jsHash(int $b): int
    {
        $x = 123456789; $k = 0;
        for ($i = 0; $i < 1677696; $i++) {
            $z = (($x + $b) ^ ($x + ($x % 3) + ($x % 17) + $b) ^ $i) & 0xffffffff;
            if ($z >= 0x80000000) $z -= 0x100000000;
            $x = $z % 16776960;
            if ($x % 117 === 0) $k = ($k + 1) % 1111;
        }
        return $k;
    }

    public function get(string $url): string
    {
        $url = self::normalise($url);
        if (!self::allowed($url)) throw new \RuntimeException('Недопустимый адрес источника');
        $host = parse_url($url, PHP_URL_HOST);
        $jar = $this->stateDir . '/cookie-' . hash('sha256', $host) . '.txt';
        $lock = fopen($jar . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Источник уже обновляется. Повторите запрос через несколько секунд.');
        try {
            $extra = '';
            $challengeUsed = false;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $body = ''; $headers = [];
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_USERAGENT => self::UA, CURLOPT_TIMEOUT => 28,
                    CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_ENCODING => '',
                    CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
                    CURLOPT_HTTPHEADER => ['Accept-Language: ru-RU,ru;q=0.9', 'Accept: text/html,application/json'],
                    CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int { $headers[] = $line; return strlen($line); },
                    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                        if (strlen($body) + strlen($chunk) > 8_000_000) return 0;
                        $body .= $chunk; return strlen($chunk);
                    },
                ]);
                if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'HTTPS');
                else curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
                if ($extra !== '') curl_setopt($ch, CURLOPT_COOKIE, $extra);
                $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST) ?: [];
                $error = curl_error($ch); unset($ch);
                if ($ok === false) throw new \RuntimeException('Ошибка соединения с источником: ' . $error);
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = '';
                    foreach ($headers as $line) if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                    if (str_starts_with($location, '/')) $location = 'https://' . $host . $location;
                    if ($location !== '' && self::allowed($location) && $location !== $url) { $url = self::normalise($location); continue; }
                }
                if ($status !== 200) throw new \RuntimeException('Источник вернул HTTP ' . $status);
                if (str_contains($body, 'function get_jhash')) {
                    $code = null;
                    foreach ($cookies as $cookie) {
                        $f = explode("\t", $cookie);
                        if (count($f) >= 7 && $f[5] === '__js_p_') $code = (int) explode(',', $f[6])[0];
                    }
                    if ($code !== null && !$challengeUsed) {
                        $challengeUsed = true;
                        $extra = '__jhash_=' . self::jsHash($code) . '; __jua_=' . rawurlencode(self::UA);
                        continue;
                    }
                    throw new \RuntimeException('Источник требует дополнительную проверку браузером');
                }
                return $body;
            }
            throw new \RuntimeException('Не удалось получить товарные данные');
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private static function normalise(string $url): string
    {
        if (!self::allowed($url)) throw new \RuntimeException('Недопустимый адрес источника');
        $p = parse_url($url); $out = 'https://' . $p['host'] . ($p['path'] ?? '/');
        if (isset($p['query'])) { parse_str($p['query'], $query); $out .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986); }
        return $out;
    }
}

