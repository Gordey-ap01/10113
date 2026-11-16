<?php
declare(strict_types=1);
namespace Poisk\Deploy;

// Bootstrap is installed once from GitHub. Release payloads can only replace the
// seven application files below, inside this one independent site directory.
const FILES = ['src/Http.php','src/Labor.php','src/State.php','src/Suppliers.php','src/Web.php','assets/app.js','assets/style.css'];
const HOST = 'poisk.xn--101-eddot8cge.xn--p1ai';
const ROOT = '/home/b/b9141846/poisk-service101';

function authorized(string $key, string $timestamp, string $release, string $signature, string $body, int $now): bool
{
    if (!preg_match('/^[0-9a-f]{64}$/D', $key) || !preg_match('/^[0-9]{10}$/D', $timestamp)
        || abs($now - (int) $timestamp) > 300 || !preg_match('/^[0-9a-f]{40}$/D', $release)
        || !preg_match('/^[0-9a-f]{64}$/D', $signature)) return false;
    return hash_equals(hash_hmac('sha256', $timestamp . "\n" . $release . "\n" . hash('sha256', $body), $key), $signature);
}

function unpack(string $archive, string $release): array
{
    $zip = new \ZipArchive();
    if ($zip->open($archive) !== true) throw new \RuntimeException('Invalid release archive');
    try {
        if ($zip->numFiles !== count(FILES) + 1) throw new \RuntimeException('Unexpected archive entries');
        $blobs = []; $seen = []; $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); $name = $stat['name'];
            if (($name !== 'manifest.json' && !in_array($name, FILES, true)) || isset($seen[$name]) || $stat['size'] > 500000) throw new \RuntimeException('Unsafe archive entry');
            $total += $stat['size'];
            if ($total > 1500000) throw new \RuntimeException('Release too large');
            $os = 0; $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $os, $attributes) && (($attributes >> 16) & 0170000) === 0120000) throw new \RuntimeException('Symlinks prohibited');
            $seen[$name] = true; $blob = $zip->getFromIndex($i);
            if (!is_string($blob) || strlen($blob) !== $stat['size']) throw new \RuntimeException('Corrupt entry');
            $blobs[$name] = $blob;
        }
        $manifest = json_decode($blobs['manifest.json'], true, 16, JSON_THROW_ON_ERROR);
        if (($manifest['release'] ?? '') !== $release || count($manifest['files'] ?? []) !== count(FILES)) throw new \RuntimeException('Invalid manifest');
        foreach (FILES as $name) if (!isset($blobs[$name], $manifest['files'][$name]) || !hash_equals(hash('sha256', $blobs[$name]), $manifest['files'][$name])) throw new \RuntimeException('File checksum mismatch');
        unset($blobs['manifest.json']); return $blobs;
    } finally { $zip->close(); }
}

function reply(array $value, int $status): never
{
    http_response_code($status); header('Content-Type: application/json'); header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex');
    echo json_encode($value, JSON_THROW_ON_ERROR); exit;
}

function handle(string $root): never
{
    if (realpath($root) !== ROOT || ($_SERVER['HTTP_HOST'] ?? '') !== HOST || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || (empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https')) reply(['error' => 'Unavailable'], 404);
    $config = require $root . '/config.php';
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2000000) reply(['error' => 'Too large'], 413);
    $body = file_get_contents('php://input', false, null, 0, 2000001);
    if (!is_string($body) || strlen($body) > 2000000) reply(['error' => 'Too large'], 413);
    $release = $_SERVER['HTTP_X_POISK_RELEASE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_POISK_TIME'] ?? '';
    if (!authorized($config['deploy_key'] ?? '', $timestamp, $release, $_SERVER['HTTP_X_POISK_SIGNATURE'] ?? '', $body, time())) reply(['error' => 'Unauthorized'], 403);
    if (!is_dir($root . '/var')) mkdir($root . '/var', 0700, true);
    $lock = fopen($root . '/var/deploy.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) reply(['error' => 'Busy'], 409);
    $temp = tempnam($root . '/var', 'release-');
    try {
        $nonce = hash('sha256', $timestamp . '|' . $release . '|' . hash('sha256', $body));
        $used = json_decode(@file_get_contents($root . '/var/deploy-used.json') ?: '{}', true) ?: [];
        if (isset($used[$nonce])) reply(['error' => 'Replay rejected'], 409);
        file_put_contents($temp, $body, LOCK_EX); $blobs = unpack($temp, $release);
        $directory = $root . '/releases/' . $release;
        if (is_link($root . '/releases') || is_link($directory)) throw new \RuntimeException('Unsafe release directory');
        if (!is_dir($directory)) {
            $stage = $root . '/releases/.' . $release . '-' . bin2hex(random_bytes(6));
            foreach ($blobs as $name => $blob) {
                $parent = dirname($stage . '/' . $name);
                if (!is_dir($parent) && !mkdir($parent, 0700, true)) throw new \RuntimeException('Cannot create release');
                if (file_put_contents($stage . '/' . $name, $blob, LOCK_EX) !== strlen($blob)) throw new \RuntimeException('Cannot write release');
            }
            if (!rename($stage, $directory)) throw new \RuntimeException('Cannot finalize release');
        } else {
            foreach ($blobs as $name => $blob) if (!is_file($directory . '/' . $name) || !hash_equals(hash('sha256', $blob), hash_file('sha256', $directory . '/' . $name))) throw new \RuntimeException('Existing release mismatch');
        }
        // An atomic pointer switch is the only activation operation. Database,
        // credentials and the other two site directories are outside its scope.
        $pointer = $root . '/var/current-' . bin2hex(random_bytes(6));
        file_put_contents($pointer, json_encode(['release' => $release], JSON_THROW_ON_ERROR), LOCK_EX);
        if (!rename($pointer, $root . '/current.json')) throw new \RuntimeException('Cannot activate release');
        $used = array_filter($used, static fn($ts) => time() - $ts < 600); $used[$nonce] = time();
        file_put_contents($root . '/var/deploy-used.json', json_encode($used, JSON_THROW_ON_ERROR), LOCK_EX);
        file_put_contents($root . '/var/deploy.log', gmdate('c') . ' ' . $release . "\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        error_log('Poisk release failure: ' . get_class($e)); reply(['error' => 'Release rejected'], 422);
    } finally { if (is_file($temp)) unlink($temp); flock($lock, LOCK_UN); fclose($lock); }
    reply(['ok' => true, 'release' => $release], 200);
}
