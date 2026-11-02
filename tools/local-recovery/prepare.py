"""Gordey_ap01: prepare a separate recovery without touching sources or backups."""
from pathlib import Path
import gzip
import hashlib
import json
import secrets
import shutil
import tarfile

ROOT = Path(__file__).resolve().parents[3]
RUNTIME = ROOT / 'runtime'
BACKUPS = Path('D:/Backups/Security-Gordey_ap01')
SOURCES = Path('D:/101')
SNAPSHOTS = {
    'static': BACKUPS / 'service101-main/20261025T234104748140Z',
    'wordpress': BACKUPS / 'service101-stage/20261025T234108438566Z',
}


def verify(folder):
    for line in (folder / 'SHA256SUMS').read_text().splitlines():
        expected, name = line.split(None, 1)
        with (folder / name.strip().lstrip('*')).open('rb') as stream:
            actual = hashlib.file_digest(stream, 'sha256').hexdigest()
        if actual != expected:
            raise RuntimeError(f'Backup checksum failed: {folder.name}/{name}')


if (RUNTIME / 'prepared.json').exists():
    raise SystemExit('Already prepared; use Start.ps1 to start without restoring over edits.')
if RUNTIME.exists() and any(RUNTIME.iterdir()):
    raise SystemExit('Runtime is not empty. Refusing to overwrite existing recovery files.')
RUNTIME.mkdir(parents=True, exist_ok=True)
for name, folder in SNAPSHOTS.items():
    verify(folder)
    destination = RUNTIME / name
    destination.mkdir()
    with tarfile.open(folder / 'files.tar.gz', 'r:gz') as archive:
        # Python data filter rejects traversal and unsafe links.
        archive.extractall(destination, filter='data')

db_password = secrets.token_hex(24)
admin_password = secrets.token_urlsafe(24)
env = {
    'RECOVERY_RUNTIME': RUNTIME.as_posix(),
    'MYSQL_ROOT_PASSWORD': secrets.token_hex(24),
    'MYSQL_PASSWORD': db_password,
    'LOCAL_ADMIN_PASSWORD': admin_password,
}
(RUNTIME / 'secrets.env').write_text(
    '\n'.join(f'{key}={value}' for key, value in env.items()) + '\n', encoding='utf-8'
)
(RUNTIME / 'mail').mkdir()
(RUNTIME / 'database.sql').write_bytes(
    gzip.decompress((SNAPSHOTS['wordpress'] / 'database.sql.gz').read_bytes())
)

# The recovered instance gets fresh local credentials, never hosting credentials.
salts = '\n'.join(
    f"define('{key}', '{secrets.token_hex(48)}');"
    for key in ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
                'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT']
)
config = """<?php
// Gordey_ap01 — isolated local snapshot; hosting configuration remains in backup.
define('DB_NAME', getenv('WORDPRESS_DB_NAME'));
define('DB_USER', getenv('WORDPRESS_DB_USER'));
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD'));
define('DB_HOST', getenv('WORDPRESS_DB_HOST'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 's101_';
define('WP_HOME', 'http://127.0.0.1:8185');
define('WP_SITEURL', 'http://127.0.0.1:8185');
define('WP_ENVIRONMENT_TYPE', 'staging');
define('FORCE_SSL_ADMIN', false);
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', true);
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');
""" + salts + "\nif (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n"
(RUNTIME / 'wordpress/wp-config.php').write_text(config, encoding='utf-8')
mu = RUNTIME / 'wordpress/wp-content/mu-plugins'
mu.mkdir(exist_ok=True)
(mu / 'gordey-local-recovery.php').write_text("""<?php
/** Plugin Name: Gordey_ap01 — локальное восстановление */
defined('ABSPATH') || exit;
add_filter('pre_wp_mail', static function ($return, $attributes) {
    file_put_contents('/var/recovery-mail/wordpress-mail.jsonl',
        wp_json_encode($attributes, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    return true;
}, 999, 2);
""", encoding='utf-8')
shutil.copy2(SOURCES / '10113-wordpress/wordpress/deploy/gordey-security-baseline.php', mu)
shutil.copy2(SOURCES / '10113-wordpress/wordpress/deploy/staging.htaccess', RUNTIME / 'wordpress/.htaccess')
static_rules = (SOURCES / '10113/.htaccess').read_text(encoding='utf-8')
static_rules = static_rules.replace('https://xn--101-eddot8cge.xn--p1ai', 'http://127.0.0.1:8184')
(RUNTIME / 'static/.htaccess').write_text(static_rules, encoding='utf-8')
(RUNTIME / 'LOCAL-ACCESS.txt').write_text(
    'Gordey_ap01 — локальные доступы\n'
    'Боевой сайт: http://127.0.0.1:8184/\n'
    'WordPress: http://127.0.0.1:8185/\n'
    'Админка: http://127.0.0.1:8185/wp-admin/\n'
    f'Логин: gordey_local\nПароль: {admin_password}\n'
    'Пароль относится только к этой локальной копии.\n', encoding='utf-8'
)
with tarfile.open(SNAPSHOTS['static'] / 'files.tar.gz', 'r:gz') as archive:
    original_index = archive.extractfile('./index.html').read()
if (RUNTIME / 'static/index.html').read_bytes() != original_index:
    raise RuntimeError('Production index changed during extraction.')
(RUNTIME / 'prepared.json').write_text(json.dumps({
    'author': 'Gordey_ap01',
    'snapshot_date_local': '2026-10-26',
    'sources': {key: str(value) for key, value in SNAPSHOTS.items()},
    'production_index_sha256': hashlib.sha256(original_index).hexdigest(),
    'github': 'https://github.com/Gordey-ap01/10113',
    'static_source': 'D:/101/10113',
    'wordpress_source': 'D:/101/10113-wordpress',
}, ensure_ascii=False, indent=2), encoding='utf-8')
print('Both snapshots prepared; checksums verified; production index preserved.')
