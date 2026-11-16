"""Read-only HTTP comparison; optional curl_cffi==0.17.0. Gordey_ap01.

No browser, logins, proxies, challenge solving or app integration. Run manually;
this diagnostic is excluded from the production package.
"""
import concurrent.futures
import json
import time
import urllib.error
import urllib.parse
import urllib.request

from curl_cffi import requests

QUERY = 'iPhone 12'
TARGETS = {
    'taggsm': ('https://taggsm.ru/index.php?' + urllib.parse.urlencode({'route':'product/searcho','filter_name':QUERY}), 'product-thumb'),
    'moba': ('https://khabarovsk.moba.ru/catalog/?' + urllib.parse.urlencode({'q':QUERY}), 'product-grid__name'),
    'dvsota': ('https://parts.dvsota.ru/catalog/?' + urllib.parse.urlencode({'q':QUERY}), 'catalog_item'),
    'greenspark': ('https://green-spark.ru/search/?' + urllib.parse.urlencode({'q':QUERY,'set_city':249529}), '__NUXT_DATA__'),
}

def probe(source, target):
    url, marker = target
    result = {'source': source}
    for client in ['urllib', 'curl_cffi']:
        start = time.monotonic()
        try:
            if client == 'urllib':
                request = urllib.request.Request(url, headers={'User-Agent':'Mozilla/5.0','Accept-Language':'ru-RU,ru;q=0.9'})
                with urllib.request.urlopen(request, timeout=20) as response:
                    status = response.status
                    body = response.read(8_000_001).decode('utf8', errors='replace')
            else:
                # Official API: https://curl-cffi.readthedocs.io/en/latest/impersonate.html
                with requests.Session() as session:
                    response = session.get(url, impersonate='chrome', timeout=20, verify=True)
                    status, body = response.status_code, response.text
            result[client] = {'http':status, 'seconds':round(time.monotonic()-start,2),
                              'product_marker':marker in body, 'js_cookie_check':'function get_jhash' in body}
        except Exception as error:
            result[client] = {'error':type(error).__name__, 'seconds':round(time.monotonic()-start,2)}
    return result

if __name__ == '__main__':
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        for result in pool.map(lambda item: probe(*item), TARGETS.items()):
            print(json.dumps(result, ensure_ascii=False), flush=True)
