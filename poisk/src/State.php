<?php
declare(strict_types=1);
namespace Poisk;

final class State
{
    public \PDO $db;
    public function __construct(public string $root, public array $config)
    {
        foreach (['var','var/sessions','var/http'] as $dir) if (!is_dir($root . '/' . $dir)) mkdir($root . '/' . $dir, 0700, true);
        $this->db = new \PDO('sqlite:' . $root . '/var/poisk.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;
          CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, login TEXT UNIQUE NOT NULL, hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "master");
          CREATE TABLE IF NOT EXISTS attempts (k TEXT NOT NULL, ts INTEGER NOT NULL);
          CREATE INDEX IF NOT EXISTS attempts_key ON attempts(k,ts);
          CREATE TABLE IF NOT EXISTS cache (k TEXT PRIMARY KEY, ts INTEGER NOT NULL, value TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS releases (id TEXT PRIMARY KEY, ts INTEGER NOT NULL);');
        $this->db->exec('DELETE FROM attempts WHERE ts < ' . (time() - 86400));
        if ($this->db->query('SELECT COUNT(*) FROM users')->fetchColumn() == 0 && !empty($config['admin_hash'])) {
            $s = $this->db->prepare('INSERT INTO users(login,hash,role) VALUES(?,?,?)');
            $s->execute([$config['admin_login'], $config['admin_hash'], 'owner']);
        }
    }
    public function limit(string $key, int $max, int $window): bool
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM attempts WHERE k=? AND ts>?'); $s->execute([$key, time() - $window]);
        if ((int) $s->fetchColumn() >= $max) return false;
        $s = $this->db->prepare('INSERT INTO attempts(k,ts) VALUES(?,?)'); $s->execute([$key, time()]); return true;
    }
    public function log(\Throwable $e): void
    {
        // No request bodies, credentials, supplier session cookies or stack traces in logs.
        file_put_contents($this->root . '/var/errors.log', gmdate('c') . ' ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 350) . "\n", FILE_APPEND | LOCK_EX);
    }
}

