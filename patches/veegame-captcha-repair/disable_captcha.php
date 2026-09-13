<?php
header('Content-Type: text/plain; charset=utf-8');
$dir = __DIR__ . '/assets/js';
if (!is_dir($dir)) { exit("ERROR: put disable_captcha.php in Veegame root containing assets/js\n"); }
$changed=0; $files=0;
foreach (glob($dir . '/index-*.js') as $file) {
    $s=file_get_contents($file); $n=$s;
    $n=preg_replace('/[A-Za-z_$][\\w$]*\\.isOpenCaptcha&&!([A-Za-z_$][\\w$]*)\\?[^:]{1,20}:\\(/', 'false?null:(', $n);
    $n=str_replace('o.isOpenCaptcha&&!P.value?D():','false?D():',$n);
    $n=str_replace('o.isOpenCaptcha&&!h.value?z():','false?z():',$n);
    $n=str_replace('o.isOpenCaptcha&&!h.value?X():','false?X():',$n);
    $n=str_replace('e.registerData.hasRegisterCaptcha==="1"?J():A("")','false?J():A("")',$n);
    if ($n!==$s) { file_put_contents($file,$n); $changed++; }
    $files++;
}
echo "Scanned JS files: $files\nChanged files: $changed\nDONE - clear browser/site cache, then delete disable_captcha.php\n";
