"""Gordey_ap01: first restore; retry safely after an interrupted startup."""
from pathlib import Path
import json
import subprocess

ROOT = Path(__file__).resolve().parents[3]
RUNTIME = ROOT / 'runtime'
COMPOSE = ['docker', 'compose', '--env-file', str(RUNTIME / 'secrets.env'),
           '-p', 'service101-recovery-20261102', '-f', str(Path(__file__).with_name('compose.yaml'))]


def run(*args, **kwargs):
    return subprocess.run(COMPOSE + list(args), check=True, **kwargs)


if not (RUNTIME / 'prepared.json').exists():
    raise SystemExit('Run prepare.py first.')
run('config', '--quiet')
run('up', '-d', '--wait', '--wait-timeout', '600', 'database', 'static')
marker = RUNTIME / 'database-imported.json'
if not marker.exists():
    with (RUNTIME / 'database.sql').open('rb') as sql:
        run('exec', '-T', 'database', 'sh', '-c',
            'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -h 127.0.0.1 --default-character-set=utf8mb4 -u "$MYSQL_USER" "$MYSQL_DATABASE"',
            stdin=sql)
    marker.write_text('{"author":"Gordey_ap01","imported":true}', encoding='utf-8')
    print('Database snapshot imported into a separate local volume.', flush=True)
run('up', '-d', 'wordpress')
# Serialization-aware replacement, including the plugin tables:
# https://developer.wordpress.org/cli/commands/search-replace/
for old in ['https://wp-stage.xn--101-eddot8cge.xn--p1ai',
            'http://wp-stage.xn--101-eddot8cge.xn--p1ai',
            'https://wp-stage.сервис101.рф', 'http://wp-stage.сервис101.рф']:
    run('run', '--rm', '--no-deps', 'cli', 'search-replace', old,
        'http://127.0.0.1:8185', '--all-tables-with-prefix', '--skip-columns=guid',
        '--precise', '--report-changed-only')
run('run', '--rm', '--no-deps', 'cli', 'eval-file', '/opt/recovery/initialize-local.php')
result = run('run', '--rm', '--no-deps', 'cli', 'eval-file', '/opt/recovery/inspect-local.php',
             capture_output=True, text=True, encoding='utf-8')
inspection = json.loads(result.stdout)
(RUNTIME / 'inspection.json').write_text(json.dumps(inspection, ensure_ascii=False, indent=2), encoding='utf-8')
(RUNTIME / 'restored.json').write_text(json.dumps({
    'author': 'Gordey_ap01', 'database_imported': True,
    'local_admin': 'gordey_local', 'snapshot_date': '2026-10-26',
    'catalog_counts': inspection['counts'],
}, ensure_ascii=False, indent=2), encoding='utf-8')
print('Both versions restored. Credentials: runtime/LOCAL-ACCESS.txt', flush=True)
