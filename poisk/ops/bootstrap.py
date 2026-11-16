"""Prepare a one-time isolated install from a committed Git tree. Gordey_ap01.

Credentials are read from a local ignored JSON file, never CLI values. The output
archive contains secrets and must stay outside the public web directory and Git.
"""
import argparse
import base64
import json
import re
import subprocess
import zipfile
from pathlib import Path
from package import FILES

def bootstrap(release, credentials, output):
    if not re.fullmatch(r'[0-9a-f]{40}', release):
        raise ValueError('Full commit SHA required')
    repo = Path(__file__).resolve().parents[2]
    def source(name):
        return subprocess.check_output(['git','show',f'{release}:poisk/{name}'],cwd=repo)
    secrets = json.loads(credentials.read_text(encoding='utf8'))
    config = {k: secrets[k] for k in ['deploy_key','admin_login','admin_hash']}
    config['origin'] = 'https://poisk.xn--101-eddot8cge.xn--p1ai'
    encoded = base64.b64encode(json.dumps(config).encode()).decode()
    blobs = {'config.php': f"<?php\nreturn json_decode(base64_decode('{encoded}'), true, 16, JSON_THROW_ON_ERROR);\n".encode(),
             'deploy.php': source('ops/deploy.php'), 'current.json': json.dumps({'release':release}).encode()}
    for name in ['index.php','.htaccess','_deploy.php']:
        blobs['public_html/'+name] = source('public/'+name)
    for name in FILES:
        blobs['releases/'+release+'/'+name] = source(name)
    output.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
        for name,blob in blobs.items(): archive.writestr(name,blob)
    print(f'Prepared isolated bootstrap: {len(blobs)} files, commit {release}; contains private configuration')

if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--release',required=True)
    parser.add_argument('--credentials',type=Path,required=True)
    parser.add_argument('--output',type=Path,required=True)
    args=parser.parse_args()
    bootstrap(args.release,args.credentials,args.output)
