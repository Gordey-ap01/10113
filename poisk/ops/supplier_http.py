"""Small HTTPS client for the supplier's public JS-cookie interstitial. Gordey_ap01."""
from urllib.parse import quote
import requests

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36'

def js_hash(b):
    def rem(x, d): return x % d if x >= 0 else -((-x) % d)
    x, k = 123456789, 0
    for i in range(1677696):
        z = (x + b) ^ (x + rem(x, 3) + rem(x, 17) + b) ^ i
        z = ((z + 2147483648) % 4294967296) - 2147483648
        x = rem(z, 16776960)
        if rem(x, 117) == 0: k = (k + 1) % 1111
    return k

def get(session, url):
    session.headers.update({'User-Agent': UA, 'Accept-Language': 'ru-RU,ru;q=0.9'})
    r = session.get(url, timeout=30)
    if 'function get_jhash' in r.text:
        cookie = next((c for c in session.cookies if c.name == '__js_p_'), None)
        if cookie is None: raise RuntimeError('No anonymous challenge cookie')
        session.cookies.set('__jhash_', str(js_hash(int(cookie.value.split(',')[0]))), domain=cookie.domain, path='/')
        session.cookies.set('__jua_', quote(UA, safe='~'), domain=cookie.domain, path='/')
        r = session.get(url, timeout=30)
    r.raise_for_status()
    return r
