"""Build the exact, isolated poisk release. Gordey_ap01."""
import argparse
import hashlib
import json
import re
import zipfile
from pathlib import Path

FILES = ['src/Http.php', 'src/Labor.php', 'src/State.php', 'src/Suppliers.php', 'src/Web.php', 'assets/app.js', 'assets/style.css']

def build(root, output, release):
    if not re.fullmatch(r'[0-9a-f]{40}', release):
        raise ValueError('Release must be a complete Git commit SHA')
    blobs = {name: (root / name).read_bytes() for name in FILES}
    manifest = {'release': release, 'author': 'Gordey_ap01', 'files': {name: hashlib.sha256(blob).hexdigest() for name, blob in blobs.items()}}
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        archive.writestr('manifest.json', json.dumps(manifest, sort_keys=True))
        for name, blob in blobs.items():
            archive.writestr(name, blob)
    print(f'Packaged {len(blobs)} files for {release}: {output.name}')

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--release', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    build(Path(__file__).resolve().parents[1], args.output, args.release)
