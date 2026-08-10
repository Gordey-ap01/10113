<?php
declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

return [
    'recipient' => '101kms@mail.ru',
    'from_email' => 'noreply@xn--101-eddot8cge.xn--p1ai',
    'from_name' => 'Сервис 101',
    'timezone' => 'Asia/Vladivostok',
    'rate_limit' => 8,
    'rate_window_seconds' => 600,
];
