"""Exercise the actual PHP installer on an isolated site, including idempotence."""
from pathlib import Path
import tempfile,subprocess,hashlib,json,shutil
base=Path(__file__).resolve().parents[1];src=base/'.w13l';manifest=json.loads((src/'tools/13l-native34.json').read_text())
with tempfile.TemporaryDirectory(dir=base) as td:
 r=Path(td);(r/'js').mkdir();(r/'tools').mkdir();shutil.copy(src/'13l-histfix.php',r/'13l-histfix.php');shutil.copy(src/'tools/13l-native34.json',r/'tools/13l-native34.json');shutil.copy(base/'tests/fixtures/native-v34/dragon-original.js',r/manifest['file'])
 index=b'<html><body><script src="/js/13l-hist25.js"></script></body></html>';(r/'index.html').write_bytes(index);(r/'.htaccess').write_text('# 13L-NO-CACHE\n')
 def run():return subprocess.check_output(['php','-r',"$_GET['key']='13l2026';include '13l-histfix.php';"],cwd=r,text=True)
 first=run();assert 'Native34: applied ' in first,first
 assert hashlib.sha256((r/manifest['file']).read_bytes()).hexdigest()==manifest['after_sha256']
 assert (r/'js/13l-hist25.js').read_bytes()==(src/'js/13l-hist25.js').read_bytes()
 native_bytes=(r/manifest['file']).stat().st_size
 second=run();assert 'Native34: already applied' in second,second
 assert (r/'index.html').read_bytes()==index
 assert (r/'.htaccess').read_text()=='# 13L-NO-CACHE\n'
 (r/manifest['file']).write_text('// unexpected version');unknown=run();assert 'FAIL unknown native build' in unknown
 assert (r/manifest['file']).read_text()=='// unexpected version'
 assert hashlib.sha256((r/'js/dragon-65oA2ftS.js.bak34').read_bytes()).hexdigest()==manifest['before_sha256']
 print(json.dumps({'pass':True,'native_bytes':native_bytes,'atomic_backup':True,'idempotent':True,'unknown_build_refused':True,'index_unchanged':True,'emitted_js_exact':True},indent=2))
