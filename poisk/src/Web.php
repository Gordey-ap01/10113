<?php
declare(strict_types=1);
namespace Poisk;
require_once __DIR__ . '/State.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Suppliers.php';
require_once __DIR__ . '/Labor.php';

function jsonResponse(array $value, int $code = 200): never
{
    http_response_code($code); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
}
function escape(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function run(string $root, string $release, array $config): void
{
    header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (in_array($path, ['/assets/app.js','/assets/style.css'], true)) {
        header('Content-Type: ' . (str_ends_with($path, '.js') ? 'text/javascript' : 'text/css') . '; charset=utf-8');
        header('Cache-Control: public, max-age=3600'); readfile($release . $path); return;
    }
    header('Cache-Control: no-store');
    if ($path === '/health') jsonResponse(['ok' => true, 'service' => 'poisk', 'author' => 'Gordey_ap01', 'release' => basename($release)]);
    if (empty($config['local']) && empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
        header('Location: ' . $config['origin'] . '/', true, 308); return;
    }
    $state = new State($root, $config);
    session_save_path($root . '/var/sessions');
    session_name(empty($config['local']) ? '__Host-poisk' : 'POISK_LOCAL');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => empty($config['local']), 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1'); session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    if (isset($_SESSION['last']) && time() - $_SESSION['last'] > 28800) { unset($_SESSION['user']); session_regenerate_id(true); }
    $_SESSION['last'] = time();
    $user = $_SESSION['user'] ?? null;
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST') {
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) jsonResponse(['error' => 'Обновите страницу и повторите действие'], 403);
    }
    if ($path === '/login' && $method === 'POST') {
        $login = is_string($_POST['login'] ?? null) ? trim($_POST['login']) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $key = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . mb_strtolower($login), $config['deploy_key']);
        if (!$state->limit('login:' . $key, 7, 900)) { page(false, $_SESSION['csrf'], 'Слишком много попыток. Повторите через 15 минут.'); return; }
        $s = $state->db->prepare('SELECT id,login,hash,role FROM users WHERE login=?'); $s->execute([$login]); $row = $s->fetch(\PDO::FETCH_ASSOC);
        if (strlen($password) <= 256 && $row && password_verify($password, $row['hash'])) {
            session_regenerate_id(true); $_SESSION['user'] = ['id' => (int) $row['id'], 'login' => $row['login'], 'role' => $row['role']];
            $_SESSION['csrf'] = bin2hex(random_bytes(24)); header('Location: /', true, 303); return;
        }
        page(false, $_SESSION['csrf'], 'Неверный логин или пароль'); return;
    }
    if (!$user) {
        if (str_starts_with((string) $path, '/api/')) jsonResponse(['error' => 'Войдите в сервис'], 401);
        page(false, $_SESSION['csrf']); return;
    }
    if ($path === '/logout' && $method === 'POST') { $_SESSION = []; session_destroy(); header('Location: /', true, 303); return; }
    if ($path === '/api/account' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $new = $input['password'] ?? ''; $old = $input['current'] ?? '';
        if (!is_string($new) || !is_string($old) || strlen($new) < 12 || strlen($new) > 128) jsonResponse(['error' => 'Новый пароль должен содержать 12–128 символов'], 422);
        $s = $state->db->prepare('SELECT hash FROM users WHERE id=?'); $s->execute([$user['id']]);
        if (!password_verify($old, $s->fetchColumn())) jsonResponse(['error' => 'Текущий пароль неверен'], 422);
        $s = $state->db->prepare('UPDATE users SET hash=? WHERE id=?'); $s->execute([password_hash($new, PASSWORD_DEFAULT, ['cost' => 12]), $user['id']]);
        session_regenerate_id(true); jsonResponse(['ok' => true]);
    }
    if ($path === '/api/search' && $method === 'GET') {
        $query = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
        $source = is_string($_GET['supplier'] ?? null) ? $_GET['supplier'] : '';
        $part = is_string($_GET['part'] ?? null) ? $_GET['part'] : 'all';
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if ($page === false) jsonResponse(['error' => 'Некорректная страница'], 422);
        if (!isset(Suppliers::LABELS[$source]) || !in_array($part, ['all','display','battery','charging','back'], true) || mb_strlen($query) < 2 || mb_strlen($query) > 100 || preg_match('/[\x00-\x1f<>]/u', $query)) jsonResponse(['error' => 'Укажите модель или артикул: от 2 до 100 символов'], 422);
        session_write_close();
        $key = hash('sha256', 'v2|' . $source . '|' . mb_strtolower($query) . '|' . $part . '|' . $page);
        $s = $state->db->prepare('SELECT ts,value FROM cache WHERE k=?'); $s->execute([$key]); $cache = $s->fetch(\PDO::FETCH_ASSOC);
        if ($cache && time() - $cache['ts'] < 300) jsonResponse(json_decode($cache['value'], true) + ['cached' => true]);
        if (!$state->limit('search:' . $user['id'], 80, 600)) jsonResponse(['error' => 'Слишком частые запросы. Подождите несколько минут.'], 429);
        try {
            $start = microtime(true); $sources = new Suppliers(new Http($root . '/var/http'));
            $data = $sources->search($source, $query, $part, $page);
            foreach ($data['items'] as &$item) $item['labor'] = Labor::price($query, $item['part'], $item['technology']);
            unset($item);
            $data += ['supplier' => $source, 'checked_at' => gmdate('c'), 'duration_ms' => (int) round((microtime(true) - $start) * 1000), 'status' => 'ok'];
            $s = $state->db->prepare('INSERT INTO cache(k,ts,value) VALUES(?,?,?) ON CONFLICT(k) DO UPDATE SET ts=excluded.ts,value=excluded.value');
            $s->execute([$key,time(),json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $state->db->exec('DELETE FROM cache WHERE ts < ' . (time() - 86400));
            jsonResponse($data + ['cached' => false]);
        } catch (\Throwable $e) { $state->log($e); jsonResponse(['supplier' => $source, 'status' => 'error', 'items' => [], 'error' => 'Не удалось обновить источник. Повторите позже или откройте сайт поставщика.', 'checked_at' => gmdate('c')], 502); }
    }
    if ($path !== '/' || $method !== 'GET') { http_response_code(404); page($user, $_SESSION['csrf'], 'Страница не найдена'); return; }
    page($user, $_SESSION['csrf']);
}
function page(array|false $user, string $csrf, string $error = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    $version = basename(dirname(__DIR__));
    ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="<?= escape($csrf) ?>"><title>Поиск запчастей · Сервис 101 · Gordey_ap01</title><link rel="stylesheet" href="/assets/style.css?v=<?= escape($version) ?>"><script defer src="/assets/app.js?v=<?= escape($version) ?>"></script></head>
<body><header class="top"><a class="brand" href="/" aria-label="Сервис 101 — поиск запчастей"><b>101</b><span>СЕРВИС<small>ПОИСК ЗАПЧАСТЕЙ</small></span></a><div class="location">Комсомольск-на-Амуре</div><?php if ($user): ?><form action="/logout" method="post"><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><span class="username"><?= escape($user['login']) ?></span><button class="quiet" type="submit">Выйти</button></form><?php endif ?></header>
<?php if (!$user): ?>
<main class="login"><span class="eyebrow">РАБОЧЕЕ МЕСТО МАСТЕРА</span><h1>Найдите нужную<br>запчасть быстрее.</h1><p>Цены и наличие у поставщиков.<br>Стоимость ремонта — рядом.</p><form action="/login" method="post" class="login-form"><h2>Вход в сервис</h2><input type="hidden" name="csrf" value="<?= escape($csrf) ?>"><label>Логин<input name="login" autocomplete="username" required maxlength="64"></label><label>Пароль<input name="password" type="password" autocomplete="current-password" required maxlength="256"></label><?php if ($error): ?><p class="error" role="alert"><?= escape($error) ?></p><?php endif ?><button class="primary" type="submit">Войти</button></form></main>
<?php else: ?>
<main class="workspace"><div class="heading"><div><span class="eyebrow">ЧЕТЫРЕ ПОСТАВЩИКА · ОДИН ПОИСК</span><h1>Запчасти для ремонта</h1></div><button id="account-open" class="quiet" type="button">Сменить пароль</button></div>
<form id="search-form" class="search"><label class="search-label">Модель устройства или артикул<input id="query" name="q" type="search" value="iPhone 12" placeholder="Например, Samsung A54 или iPhone 12" required minlength="2" maxlength="100"></label><button class="primary" type="submit" id="search-button">Найти у поставщиков</button><div class="part-buttons" role="group" aria-label="Тип запчасти"><button type="button" data-part="display" aria-pressed="true">Экран</button><button type="button" data-part="battery" aria-pressed="false">Аккумулятор</button><button type="button" data-part="charging" aria-pressed="false">Нижняя плата / шлейф</button><button type="button" data-part="back" aria-pressed="false">Задняя крышка</button><button type="button" data-part="all" aria-pressed="false">Все детали</button></div></form>
<div class="sources" id="sources" aria-live="polite"></div>
<section class="filters" aria-label="Фильтры предложений"><label>Поставщик<select id="filter-source"><option value="all">Все поставщики</option><option value="taggsm">TAGGSM</option><option value="moba">MOBA</option><option value="dvsota">DVSOTA</option><option value="greenspark">GreenSpark</option></select></label><label>Технология<select id="filter-tech"><option value="all">Любая</option><option>AMOLED</option><option>OLED</option><option>TFT / IPS</option><option>LCD</option><option value="unknown">Не указана</option></select></label><label>Оригинальность<select id="filter-origin"><option value="all">Любая</option><option value="original_claimed">Оригинал по описанию</option><option value="copy">Копия / аналог</option><option value="unknown">Не указана</option></select></label><label>Состояние<select id="filter-condition"><option value="all">Любое</option><option value="new">Новое по описанию</option><option value="pulled">Снятое</option><option value="restored">Восстановленное</option><option value="unknown">Не указано</option></select></label><label>Цена детали до, ₽<input type="number" id="filter-price" min="0" placeholder="Без лимита"></label><label class="check"><input type="checkbox" id="filter-stock">Только в наличии</label><label class="check"><input type="checkbox" id="filter-local">В моём городе</label><label class="check"><input type="checkbox" id="include-labor" checked>Сразу + цена работы</label></section>
<section aria-label="Результаты поиска"><div class="results-head"><span id="result-count" aria-live="polite">Введите модель и выполните поиск</span><label>Сортировка<select id="sort"><option value="price">По выбранной сумме ↑</option><option value="supplier">По поставщику</option></select></label></div><div id="results" class="results"></div><p class="notice">Цены и наличие получаются с сайтов поставщиков. Оригинальность и состояние определяются по их описаниям. Точную доставку в Комсомольск-на-Амуре нужно уточнить; она не включена в сумму.</p></section>
<details class="rates"><summary>Стоимость работы по прайсу «Сервиса 101»</summary><div class="rate-list"><p>Нижняя плата — <b>2 000 ₽</b></p><p>Экран TFT / IPS / LCD — <b>2 500 ₽</b></p><p>Экран OLED / AMOLED — <b>4 000 ₽</b></p><p>Аккумулятор Android — <b>2 000 ₽</b></p><p>Аккумулятор iPhone — <b>4 000 ₽</b></p><p>Экран iPhone до 11 / с 12 — <b>4 000 / 5 000 ₽</b></p><p>Нижний шлейф iPhone до 11 / с 12 — <b>3 000 / 4 000 ₽</b></p><p>Крышка iPhone до 11 / 12–16 — <b>7 000 / 10 000 ₽</b></p></div><p class="notice">Ставки iPhone имеют приоритет. Для неизвестных технологий и неописанных моделей ставка требует уточнения. Наценка на деталь не добавляется.</p></details>
<dialog id="account-dialog"><form id="account-form"><h2>Сменить пароль</h2><label>Текущий пароль<input name="current" type="password" autocomplete="current-password" required maxlength="256"></label><label>Новый пароль<input name="password" type="password" autocomplete="new-password" required minlength="12" maxlength="128"></label><p id="account-message" role="status"></p><div class="dialog-actions"><button class="quiet" id="account-close" type="button">Закрыть</button><button class="primary" type="submit">Сохранить</button></div></form></dialog>
</main><?php endif ?>
<footer><span>Сервис 101 · Поиск запчастей</span><strong>Идея и продукт · Gordey_ap01</strong></footer></body></html>
<?php }
