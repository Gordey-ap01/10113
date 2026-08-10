<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

/** @var array<string, mixed> $config */
$config = require __DIR__ . '/mail-config.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'Asia/Vladivostok'));

function respond(int $status, bool $ok, string $message): void
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $ok, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function clean_value(mixed $value, int $limit = 3000): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = trim((string) $value);
    $text = str_replace(["\0", "\r"], ['', ''], $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $limit, 'UTF-8')
        : substr($text, 0, $limit);
}

function request_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return preg_replace('/:\d+$/', '', $host) ?? '';
}

function same_origin_request(): bool
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return true;
    }

    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    return $originHost !== '' && hash_equals(request_host(), $originHost);
}

function enforce_rate_limit(array $config): void
{
    $limit = max(1, (int) ($config['rate_limit'] ?? 8));
    $window = max(60, (int) ($config['rate_window_seconds'] ?? 600));
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'service101-form-'
        . hash('sha256', $ip)
        . '.json';
    $handle = @fopen($file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return;
    }

    $raw = stream_get_contents($handle);
    $entries = json_decode($raw ?: '[]', true);
    if (!is_array($entries)) {
        $entries = [];
    }

    $now = time();
    $entries = array_values(array_filter(
        $entries,
        static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - $window
    ));

    if (count($entries) >= $limit) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond(429, false, 'Слишком много попыток. Повторите отправку через несколько минут.');
    }

    $entries[] = $now;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($entries));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Метод запроса не поддерживается.');
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    respond(413, false, 'Заявка слишком большая.');
}

if (!same_origin_request()) {
    respond(403, false, 'Источник запроса не разрешён.');
}

if (clean_value($_POST['website'] ?? '') !== '') {
    respond(200, true, 'Заявка отправлена.');
}

enforce_rate_limit($config);

$name = clean_value($_POST['Имя'] ?? '', 160);
$phone = clean_value($_POST['Телефон'] ?? '', 80);
if ($name === '' || $phone === '') {
    respond(422, false, 'Укажите имя и номер телефона.');
}

$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) {
    respond(422, false, 'Проверьте номер телефона.');
}

$email = clean_value($_POST['Email'] ?? '', 254);
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    respond(422, false, 'Проверьте адрес электронной почты.');
}

$formType = clean_value($_POST['_form_type'] ?? 'contact', 32);
$subjects = [
    'contact' => 'Заявка с сайта Сервис 101',
    'onsite' => 'Заявка на выезд мастера — Сервис 101',
    'b2b' => 'Заявка от организации — Сервис 101',
    'booking' => 'Запись на ремонт — Сервис 101',
];
$subject = $subjects[$formType] ?? $subjects['contact'];

$ignored = ['_subject', '_template', '_captcha', '_next', '_form_type', 'website', 'branch_choice'];
$labels = [];
foreach ($_POST as $key => $value) {
    $label = clean_value($key, 100);
    if ($label === '' || in_array($label, $ignored, true)) {
        continue;
    }

    $clean = clean_value($value);
    if ($clean !== '') {
        $labels[$label] = $clean;
    }
}

$labels['Дата и время'] = date('d.m.Y H:i:s');
$rows = '';
foreach ($labels as $label => $value) {
    $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeValue = nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $rows .= '<tr>'
        . '<th style="width:34%;padding:12px 14px;background:#eef6fb;border:1px solid #d7e4ee;text-align:left;vertical-align:top;font-family:Arial,sans-serif;font-size:14px;color:#0f172a">'
        . $safeLabel
        . '</th>'
        . '<td style="padding:12px 14px;border:1px solid #d7e4ee;font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#1e293b">'
        . $safeValue
        . '</td></tr>';
}

$safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$message = '<!doctype html><html lang="ru"><head><meta charset="UTF-8"></head>'
    . '<body style="margin:0;padding:24px;background:#f5f8fb">'
    . '<div style="max-width:720px;margin:0 auto;background:#fff;border:1px solid #d7e4ee;border-radius:14px;overflow:hidden">'
    . '<div style="padding:20px 24px;background:#078fc7;color:#fff;font-family:Arial,sans-serif">'
    . '<div style="font-size:13px;opacity:.85">Сервис 101</div>'
    . '<h1 style="margin:4px 0 0;font-size:22px">' . $safeSubject . '</h1></div>'
    . '<div style="padding:22px"><table role="presentation" style="width:100%;border-collapse:collapse">'
    . $rows
    . '</table></div></div></body></html>';

$recipient = (string) ($config['recipient'] ?? '');
$fromEmail = (string) ($config['from_email'] ?? '');
$fromName = (string) ($config['from_name'] ?? 'Сервис 101');
if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
    error_log('Service 101 form: invalid mail configuration');
    respond(503, false, 'Отправка временно недоступна. Позвоните нам: +7 (994) 076-01-01.');
}

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
    'X-Mailer: Service101 Website',
];
if ($email !== '') {
    $headers[] = 'Reply-To: ' . $email;
}

$sent = @mail($recipient, $encodedSubject, $message, implode("\r\n", $headers));
if (!$sent) {
    error_log('Service 101 form: mail() returned false');
    respond(503, false, 'Не удалось отправить заявку. Позвоните нам: +7 (994) 076-01-01.');
}

respond(200, true, 'Заявка отправлена. Мастер скоро свяжется с вами.');
