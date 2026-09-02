# Same-origin proxy (no front-end changes)

Use this when the game front-end will not attach its token to a different
domain — the usual symptom is `GetBalance` returning **401** while the public
endpoints work, and the request carrying only `signature`, `timestamp` and
`random`.

## Why it happens

Browsers and SPA HTTP clients normally attach the auth header only to their own
API base. Calls that go straight to another host arrive **without any token**,
so the engine cannot tell who the player is.

## The fix

Keep every lottery call on your own domain and let one small PHP file forward
it, adding the token server-side:

```
browser →  https://your-site.com/api/Lottery/GetBalance     (same origin, token attached as usual)
proxy   →  https://api.example.com/api/Lottery/GetBalance   (+ Authorization, + X-Api-Key)
```

No CORS, no front-end rebuild, and the player's own token is used.

## Install (3 steps)

1. Copy `lottery-proxy.php` to the **root** of your site.
2. Edit the two constants at the top:

```php
const LOTTERY_BASE = 'https://api.devlopedwithzayro.site';
const LOTTERY_KEY  = 'your domain api key';   // admin panel → Domains
```

3. Add the rewrite **above** your existing rules.

**Apache / cPanel** — in `.htaccess`:

```apache
RewriteEngine On
RewriteRule ^api/Lottery/(.*)$ lottery-proxy.php [QSA,L]
```

**Nginx**:

```nginx
location /api/Lottery/ {
    try_files $uri /lottery-proxy.php$is_args$args;
}
```

Then point the front-end's lottery base back at your own domain (that is
usually its default), and reload.

## Check it works

```bash
curl -s "https://your-site.com/api/Lottery/GetGameList" | head -c 200

curl -s "https://your-site.com/api/Lottery/Whoami" \
  -H "Authorization: Bearer <a real player token from your site>"
```

`Whoami` should answer `"valid": true` with the mapped `userId`.

## Requirements

- The domain must be whitelisted in the admin panel (→ Domains).
- That domain needs a **token check URL** so the engine can ask your site who a
  token belongs to, e.g. `https://your-site.com/api/User/GetUserInfo`:

```bash
php tools/domain.php check your-site.com https://your-site.com/api/User/GetUserInfo POST
```
