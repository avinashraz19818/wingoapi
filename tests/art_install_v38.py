"""Missing native art restore, idempotence, tampered payload and unknown-file guards."""
from pathlib import Path
import tempfile,subprocess,hashlib,json,shutil
base=Path(__file__).resolve().parents[1];src=base/'.w13l';art=json.loads((src/'tools/13l-art38.json').read_text())
with tempfile.TemporaryDirectory(dir=base) as td:
 r=Path(td);(r/'js').mkdir();(r/'tools').mkdir();(r/'images').mkdir()
 shutil.copy(src/'13l-histfix.php',r/'13l-histfix.php')
 for name in ['13l-native34.json','13l-native36.json','13l-art38.json']:shutil.copy(src/'tools'/name,r/'tools'/name)
 native=(base/'tests/fixtures/native-v34/dragon-original.js').read_text()
 for name in ['13l-native34.json','13l-native36.json']:
  for pair in json.loads((src/'tools'/name).read_text())['replacements']:native=native.replace(pair['old'],pair['new'])
 (r/'js/dragon-65oA2ftS.js').write_text(native)
 index=b'<html><body><script src="/js/13l-hist25.js"></script></body></html>';(r/'index.html').write_bytes(index);(r/'.htaccess').write_text('# 13L-NO-CACHE\n')
 (r/'images/other.webp').write_bytes(b'untouched')
 def run():return subprocess.check_output(['php','-r',"$_GET['key']='13l2026';include '13l-histfix.php';"],cwd=r,text=True)
 first=run();assert 'Art38: restored 135480 B' in first,first
 target=r/art['file'];assert hashlib.sha256(target.read_bytes()).hexdigest()==art['sha256'];assert target.stat().st_size==135480
 mtime=target.stat().st_mtime_ns;assert 'Art38: already present 135480 B' in run();assert target.stat().st_mtime_ns==mtime
 assert (r/'index.html').read_bytes()==index;assert (r/'images/other.webp').read_bytes()==b'untouched';assert (r/'js/dragon-65oA2ftS.js').read_text()==native
 target.write_bytes(b'custom art');assert 'FAIL unknown winning art preserved' in run();assert target.read_bytes()==b'custom art'
 target.unlink();bad=dict(art);bad['base64']='AAAA';(r/'tools/13l-art38.json').write_text(json.dumps(bad));assert 'FAIL missing/corrupt winning art manifest' in run();assert not target.exists()
 print(json.dumps({'pass':True,'restoredBytes':135480,'sha256':art['sha256'],'idempotent':True,'unknownArtPreserved':True,'badPayloadRejected':True,'nativeAndIndexUnchanged':True,'unrelatedImagesUntouched':True},indent=2))
