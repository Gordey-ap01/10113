<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require dirname(__DIR__) . '/deploy.php';
\Poisk\Deploy\handle(dirname(__DIR__));
