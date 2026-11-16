"""Publish a signed release to the fixed poisk endpoint, with TLS verification. Gordey_ap01."""
import hashlib
import hmac
import json
import os
import re
import sys
import time
import urllib.request
from pathlib import Path

ORIGIN = 'https://poisk.xn--101-eddot8cge.xn--p1ai'

def publish(path, release):
    key = os.environ['POISK_DEPLOY_KEY']
    if not re.fullmatch(r'[0-9a-f]{64}', key) or not re.fullmatch(r'[0-9a-f]{40}', release):
        raise ValueError('Invalid deployment configuration')
    body = Path(path).read_bytes()
    timestamp = str(int(time.time()))
    message = '\n'.join([timestamp, release, hashlib.sha256(body).hexdigest()])
    signature = hmac.new(key.encode(), message.encode(), hashlib.sha256).hexdigest()
    request = urllib.request.Request(ORIGIN + '/_deploy.php', data=body, method='POST', headers={
        'Content-Type': 'application/zip', 'X-Poisk-Time': timestamp,
        'X-Poisk-Release': release, 'X-Poisk-Signature': signature,
    })
    # Never follow a deployment redirect to another host.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None
    opener = urllib.request.build_opener(NoRedirect)
    with opener.open(request, timeout=60) as response:
        result = json.load(response)
    if result.get('release') != release or result.get('ok') is not True:
        raise RuntimeError('Deployment did not confirm the expected release')
    with opener.open(ORIGIN + '/health', timeout=30) as response:
        health = json.load(response)
    if health.get('release') != release or health.get('ok') is not True:
        raise RuntimeError('Health check did not confirm the deployed commit')
    print('Published and verified ' + release)

if __name__ == '__main__':
    publish(sys.argv[1], sys.argv[2])
