#!/usr/bin/env python3
"""Build release assets locally. Does not upload or publish anything."""
import hashlib
import json
import pathlib
import re
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
SLUG = 'congo-shop-whatsapp-order'
source = (ROOT / (SLUG + '.php')).read_text()

def header(name):
    match = re.search(r'^\s*\*\s*' + re.escape(name) + r':\s*(\S+)', source, re.M)
    if not match:
        raise SystemExit('Missing header: ' + name)
    return match[1]

version = header('Version')
if not re.fullmatch(r'\d+\.\d+\.\d+', version):
    raise SystemExit('Use a stable numeric version X.Y.Z')
constant = re.search(r"define\(\s*'CSWA_VERSION',\s*'([^']+)'", source)
if not constant or constant[1] != version:
    raise SystemExit('Version header and CSWA_VERSION differ')
out = ROOT / 'dist' / version
out.mkdir(parents=True, exist_ok=True)
archive = out / f'{SLUG}-{version}.zip'
# Explicit inclusion excludes Git history, tests, credentials and development files.
files = [ROOT / (SLUG + '.php'), ROOT / 'README.md']
for directory in ('includes', 'assets', 'templates'):
    files += sorted((ROOT / directory).rglob('*'))
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
    for file in files:
        if file.is_file():
            if file.is_symlink():
                raise SystemExit('Symlinks are not allowed: ' + str(file))
            z.write(file, str(pathlib.PurePosixPath(SLUG) / file.relative_to(ROOT)))
manifest = dict(slug=SLUG, version=version, requires=header('Requires at least'), requires_php=header('Requires PHP'))
(out / 'update.json').write_text(json.dumps(manifest, indent=2) + '\n')
(out / 'SHA256SUMS.txt').write_text(hashlib.sha256(archive.read_bytes()).hexdigest() + '  ' + archive.name + '\n')
print(archive)
print(out / 'update.json')
