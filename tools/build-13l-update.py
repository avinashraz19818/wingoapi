#!/usr/bin/env python3
"""Package ONLY .w13l source; never include site core/index.html."""
from pathlib import Path
import zipfile, hashlib, json
base=Path(__file__).resolve().parents[1]; source=base/'.w13l'
roots='13l-setup.php 13l-fixfeed.php 13l-repair.php 13l-repair2.php 13l-doctor.php 13l-net.php 13l-deploy.php 13l-cssinject.php 13l-jsinject.php 13l-unclip.php 13l-allfix.php 13l-v19.php 13l-skinsync.php 13l-restore.php 13l-rows.php 13l-histfix.php'.split()
files=[source/f for f in roots+['README-13L.txt']]
for directory in ['api','css','js','tools']:
    files+=sorted(p for p in (source/directory).rglob('*') if p.is_file())
assert len(roots)==16 and all(p.is_file() and not p.is_symlink() for p in files)
assert not any(p.name=='index.html' or p.name.startswith('.') for p in files)
assert (source/'js/13l-hist25.js').read_text().startswith('/* 13L-HIST25-v31')
dest=base/'13l-update/13l-update.zip'
with zipfile.ZipFile(dest,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for p in files:
        info=zipfile.ZipInfo(p.relative_to(source).as_posix(),(2026,9,8,0,0,0))
        info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
        z.writestr(info,p.read_bytes(),compresslevel=9)
with zipfile.ZipFile(dest) as z:
    assert z.testzip() is None
print(json.dumps({'zip':str(dest.relative_to(base)), 'bytes':dest.stat().st_size,
 'sha256':hashlib.sha256(dest.read_bytes()).hexdigest(), 'files':len(files)},indent=2))
