"""Gordey_ap01: smoke checks against the recovered local sites only."""
from concurrent.futures import ThreadPoolExecutor
from http.cookiejar import CookieJar
from pathlib import Path
import hashlib
import json
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[3]
RUNTIME = ROOT / 'runtime'
STATIC = 'http://127.0.0.1:8184'
WP = 'http://127.0.0.1:8185'
FORM_ENDPOINTS = {STATIC: STATIC + '/api/send-request.php',
                  WP: WP + '/wp-admin/admin-post.php?action=s101_request'}
inspection = json.loads((RUNTIME / 'inspection.json').read_text(encoding='utf-8'))
checks = []


class LocalRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if not newurl.startswith((STATIC + '/', WP + '/')):
            raise RuntimeError('Unexpected external redirect during local test.')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def request(url, data=None, headers=None, opener=None):
    opener = opener or urllib.request.build_opener(LocalRedirect)
    req = urllib.request.Request(url, data=data, headers=headers or {})
    try:
        with opener.open(req, timeout=40) as response:
            return response.status, response.read().decode('utf-8', errors='replace'), response.geturl()
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode('utf-8', errors='replace'), error.geturl()


def expect(name, passed):
    checks.append({'name': name, 'passed': bool(passed)})


for base in [STATIC, WP]:
    for path in ['/', '/b2b/']:
        status, body, final = request(base + path)
        expect(f'{base}{path} renders', status == 200 and 'Сервис 101' in body and 'Gordey_ap01' in body)
    for path in ['/wp-config.php', '/api/mail-config.php', '/.git/config']:
        status, _, _ = request(base + path)
        expect(f'{base}{path} protected', status in [403, 404])
    status, _, _ = request(FORM_ENDPOINTS[base])
    expect(f'{base} form rejects GET', status == 405)
    status, _, _ = request(FORM_ENDPOINTS[base], data=b'', headers={'Origin': 'https://example.invalid'})
    expect(f'{base} form rejects foreign origin', status == 403)

failed_routes = []
empty_routes = []


def check_route(path):
    status, body, _ = request(WP + path)
    return path, status, 'price-row' in body, 'Услуги и цены уточняются.' in body


with ThreadPoolExecutor(max_workers=5) as pool:
    for path, status, has_prices, empty_state in pool.map(check_route, inspection['catalog_paths']):
        if empty_state:
            empty_routes.append(path)
        if status != 200 or not (has_prices or empty_state):
            failed_routes.append({'path': path, 'status': status, 'prices': has_prices})
expect('all restored catalog routes render prices or an explicit empty state', not failed_routes)

status, _, final = request(STATIC + '/remont/telefony/')
expect('static catalog redirect stays local', status == 200 and final.startswith(STATIC + '/'))

env = dict(line.split('=', 1) for line in (RUNTIME / 'secrets.env').read_text().splitlines() if '=' in line)
opener = urllib.request.build_opener(LocalRedirect, urllib.request.HTTPCookieProcessor(CookieJar()))
request(WP + '/wp-login.php', opener=opener)
login = urllib.parse.urlencode({'log': 'gordey_local', 'pwd': env['LOCAL_ADMIN_PASSWORD'],
                               'wp-submit': 'Log In', 'redirect_to': WP + '/wp-admin/', 'testcookie': '1'}).encode()
status, body, final = request(WP + '/wp-login.php', data=login, opener=opener)
expect('local administrator signs in', status == 200 and '/wp-admin/' in final and 'wpadminbar' in body)

mail_path = RUNTIME / 'mail/static-outbox.eml'
for base in [STATIC, WP]:
    before = mail_path.stat().st_size if mail_path.exists() else 0
    form = urllib.parse.urlencode({'Имя': 'Локальная проверка восстановления Gordey_ap01',
                                  'Телефон': '+79990000000', '_form_type': 'contact',
                                  'website': '', 'Комментарий': 'Synthetic local test; do not contact.'}).encode()
    status, body, _ = request(FORM_ENDPOINTS[base], data=form, headers={'Origin': base})
    after = mail_path.stat().st_size if mail_path.exists() else 0
    payload = json.loads(body)
    if base == STATIC:
        expect(f'{base} form succeeds and mail is captured locally',
               status == 200 and payload.get('ok') is True and after > before)
    else:
        expect(f'{base} form succeeds in the original staging mode without sending mail',
               status == 200 and payload.get('ok') is True
               and payload.get('message') == 'Тестовая заявка принята. Письмо не отправлялось.'
               and after == before)

prepared = json.loads((RUNTIME / 'prepared.json').read_text(encoding='utf-8'))
expect('production index remains byte-identical to hosting backup',
       hashlib.sha256((RUNTIME / 'static/index.html').read_bytes()).hexdigest() == prepared['production_index_sha256'])
expect('catalog counts match the backup', all(inspection['counts'][key] == count
       for key, count in {'s101_devices': 118, 's101_prices': 1941, 's101_requests': 12}.items()))
result = {'author': 'Gordey_ap01', 'checks': checks,
          'catalog_routes_checked': len(inspection['catalog_paths']), 'failed_routes': failed_routes,
          'empty_catalog_paths': empty_routes,
          'passed': all(check['passed'] for check in checks)}
(RUNTIME / 'verification.json').write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps(result, ensure_ascii=False, indent=2))
raise SystemExit(0 if result['passed'] else 1)
