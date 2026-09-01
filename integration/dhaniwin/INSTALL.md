# Installing the bridge on the site

Two ways — pick whichever is easier. Both do exactly the same thing.

## A. One command (VS Code terminal, in the site's git checkout)

```powershell
git checkout -b lottery-engine-bridge
git apply --3way path\to\lottery-engine-bridge.patch
git add -A
git commit -m "Add the lottery engine bridge"
git push -u origin lottery-engine-bridge
```

Then upload the four changed files to cPanel (or `git pull` on the server).

## B. cPanel File Manager (no git)

1. Upload **`_lottery_upstream.php`** into `public_html/api/`
2. Replace `public_html/api/_router.php` with the copy here
3. Replace `public_html/api/_draw_router.php` with the copy here
4. Edit `public_html/api/_bootstrap.php` and add these four lines **directly
   under `declare(strict_types=1);`** (line 2):

```php
// Lottery upstream bridge (no-op unless lottery_upstream_url is configured).
if (is_file(__DIR__ . '/_lottery_upstream.php')) {
    require_once __DIR__ . '/_lottery_upstream.php';
}
```

## Turn it on

Admin panel → **Settings** → add:

| Key | Value |
|---|---|
| `lottery_upstream_url` | `https://api.devlopedwithzayro.site/api/Compat` |
| `lottery_upstream_key` | your domain's API key |
| `lottery_upstream_wallet` | `1` |

## Check

```
https://your-site.com/webapi/kv/issue/WinGo_30S
https://your-site.com/WinGo/WinGo_30S/GetHistoryIssuePage.json
```

The issue numbers must match the engine (`php tools/domain.php list` side has
them too), and the game must show the engine's balance.

## Roll back

Clear `lottery_upstream_url` in the admin settings. Everything returns to the
local generator immediately — no file changes needed.

## Remove the .htaccess proxy

If the `lottery-proxy.php` rewrites were added earlier, delete these three
lines — the bridge replaces them:

```apache
RewriteRule ^api/Lottery/(.*)$ lottery-proxy.php [QSA,L]
RewriteRule ^webapi/(kv|v)/issue/([^/]+)$ lottery-proxy.php [QSA,L]
RewriteRule ^(WinGo|K3|D5|MotoRace|TrxWinGo)/(.+\.json)$ lottery-proxy.php [QSA,L]
```

and restore the site's own two draw rules:

```apache
RewriteRule ^webapi/(kv|v)/issue/([^/]+)$ api/_draw_router.php?gameCode=$2 [QSA,L]
RewriteRule ^(WinGo|K3|D5|MotoRace|TrxWinGo)/(.+\.json)$ api/_draw_router.php?path=$1/$2 [QSA,L]
```
