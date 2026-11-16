<?php
declare(strict_types=1);
ini_set('display_errors', '0');
$root = dirname(__DIR__);
$config = is_file($root . '/config.php') ? require $root . '/config.php' : null;
$pointer = is_file($root . '/current.json') ? json_decode(file_get_contents($root . '/current.json'), true) : null;
$id = $pointer['release'] ?? '';
if (!$config || !preg_match('/^[a-f0-9]{40}$/D', $id) || !is_file($root . '/releases/' . $id . '/src/Web.php')) {
    http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); header('Retry-After: 300');
    echo "Поиск запчастей Сервиса 101 готовится к запуску. Gordey_ap01"; exit;
}
$release = $root . '/releases/' . $id;
require $release . '/src/Web.php';
try { \Poisk\run($root, $release, $config); }
catch (Throwable $e) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'Сервис временно недоступен. Повторите позже.'; }

