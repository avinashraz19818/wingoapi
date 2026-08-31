# Result feed & domain whitelist

This is the reseller side of the platform: results come **in** from an upstream
provider (or from the local generator), and go **out** under *your* domain to
customers you have whitelisted. Your customers never learn the upstream URL.

```
  upstream provider            your server                 your customers
  draw.ar-lottery01.com  ──▶   api.yourdomain.com   ──▶   client-site.com
  (hidden, server-side)        mirror + whitelist          only if whitelisted
```

---

## 1. Where results come from

Priority per round:

1. **Admin override** — forced from the panel (Live games → Risk → Force)
2. **Upstream provider** — `DRAW_BASE_URL`, 5s timeout
3. **Local generator** — provably-fair HMAC-SHA256, always available

If a provider is unreachable it is skipped for `DRAW_FAILURE_COOLDOWN` seconds
so the worker never stalls. Set `FORCE_REMOTE_DRAW=true` if a round must stay
undrawn rather than fall back locally.

### Provider profiles

A profile bundles the URL shape, headers, family naming and issue prefixes:

```ini
DRAW_PROFILE=ar-lottery01
DRAW_BASE_URL=https://draw.ar-lottery01.com
DRAW_ADOPT_ISSUE_PREFIXES=true
```

`ar-lottery01` sets:

| Item | Value |
|---|---|
| URL | `{base}/{family}/{code}/GetHistoryIssuePage.json` (falls back to `GetNoaverageEmerdList.json`, then `{code}.json`) |
| Headers | browser `User-Agent`, `Referer: https://ar-lottery01.com/`, gzip |
| Family naming | `D5` → `5D` in the path |
| Issue prefixes | WinGo 30S `10003` · 1M `10001` · 3M `10002` · 5M `10004` · 10M `10005` |

Because prefixes are adopted, **our 17-digit issue numbers are identical to the
upstream's**, so players comparing two sites see the same round IDs.

`generic` (default) uses `{base}/{game}/{interval}.json` and our own prefixes.

### Verify before switching

```bash
php tools/draw_probe.php WinGo_1M
php tools/draw_probe.php WinGo_1M --base=https://draw.ar-lottery01.com
```

It prints the endpoint, HTTP status, how many rows were indexed and whether our
newest finished issue parses. Exit code `0` = usable.

### Matching rounds that are numbered differently

Rows are indexed twice — by their exact issue number **and** by
`date + 4-digit sequence`. A provider that uses another game prefix for the same
round still resolves correctly.

---

## 2. What your customers call

Provider-compatible URLs, so an existing integration only swaps the host:

| Endpoint | Purpose |
|---|---|
| `GET /{Family}/{GameCode}/GetHistoryIssuePage.json?pageSize=10` | last N drawn rounds |
| `GET /{Family}/{GameCode}/GetNoaverageEmerdList.json` | alias of the above |
| `GET /{Family}/{GameCode}/GetGameIssue.json` | current round + countdown |
| `GET /api/Feed?action=GameList` | every game we publish, with its URLs |
| `GET /api/Feed?action=History&gameCode=WinGo_1M` | same as history, query style |
| `GET /api/Feed?action=Result&gameCode=WinGo_1M&issueNumber=…` | one round |

Example response (WinGo):

```json
{
  "data": {
    "gameCode": "WinGo_1M", "pageNo": 1, "pageSize": 10, "totalCount": 1440,
    "list": [
      { "issueNumber": "20260831100011075", "gameCode": "WinGo_1M",
        "drawTime": "2026-08-31 17:55:00", "source": "remote",
        "number": 7, "colour": "green", "color": "green", "premium": "7",
        "size": "big", "parity": "odd" }
    ]
  },
  "code": 0, "msg": "success", "msgCode": "SUCCESS", "serviceTime": 1788179159301
}
```

Per family the row also carries: **K3** `dice`, `openCode`, `sum`, `size`,
`parity`, `triple` · **D5** `digits`, `openCode`, `A`–`E`, `sum` ·
**MotoRace** `ranking`, `champion`, `podium` · **TrxWinGo** `blockHash`,
`blockHeight`.

---

## 3. Domain whitelist

**Nobody reads the feed unless you allow them.** Admin panel → **Domains**.

A caller is identified in this order:

1. `X-Api-Key` header (or `?key=`) — server-to-server
2. `Origin` / `Referer` host — browser calls from the customer's site
3. your own host — the built-in board and panel always work

Blocked callers get `403` with `msgCode: DOMAIN_NOT_ALLOWED`, **no CORS header
and no data**:

```json
{"data":null,"code":1002,"msg":"Domain not whitelisted: copycat.com",
 "msgCode":"DOMAIN_NOT_ALLOWED","serviceTime":1788179159301}
```

Rules enforced per domain:

| Control | Effect |
|---|---|
| status | `Block` cuts access instantly |
| games | empty = all games; or e.g. `WinGo_1M,WinGo_3M` — anything else is refused |
| expiry | access stops after the date (subscription end) |
| rate limit | per-minute budget for that customer (0 = global `FEED_RATE_LIMIT`) |
| API key | rotate any time; the old key dies immediately |
| counters | allowed vs blocked requests, daily breakdown, last-seen |

`www.` and ports are normalised, so `https://WWW.Client-Site.com:443/x` matches
`client-site.com`. A leaked API key used from a different Origin is rejected.

### Customer integration

Browser (from the whitelisted site — no key needed, CORS is granted to them):

```js
const res  = await fetch('https://api.yourdomain.com/WinGo/WinGo_1M/GetHistoryIssuePage.json?pageSize=10');
const body = await res.json();
console.log(body.data.list[0].number);
```

Server-to-server (no Origin header, so the key identifies them):

```bash
curl -H "X-Api-Key: e7e764d523873e7d0779a9528a711dda" \
  "https://api.yourdomain.com/api/Feed?action=History&gameCode=K3_1M&pageSize=5"
```

### Managing from the admin API

| Action | Method | Params |
|---|---|---|
| `Domains` | GET | `search?`, paging |
| `SaveDomain` | POST | `domain`, `label?`, `games?`, `rateLimit?`, `expiresAt?`, `note?`, `id?` (edit) |
| `SetDomainStatus` | POST | `id`, `status` (0/1) |
| `RotateDomainKey` | POST | `id` |
| `DeleteDomain` | POST | `id` |
| `DomainUsage` | GET | `id`, `days?` |
| `FeedInfo` | GET | — (all endpoints + upstream status) |

---

## 4. Public results board

`https://api.yourdomain.com/results` — every family as a tab, each game as a
card with a live countdown, the latest draw and the last 10 rounds. It reads the
same feed from your own origin, so it works without any whitelist entry. Turn it
off with `FEED_BOARD_ENABLED=false`.

To embed the board (or your own UI) on a customer site, whitelist that domain
first — otherwise their browser calls are refused.

---

## 5. Operating notes

- The worker mirrors upstream every round; if upstream is late, the round is
  drawn locally and the row is marked `source: local` (visible in the panel).
- `source` values: `remote` (upstream), `override` (forced by you), `local`.
- Results are immutable once stored — a late upstream row never rewrites a
  round players already saw.
- Feed responses carry `Cache-Control: public, max-age=2`; put Cloudflare in
  front if you resell to high-traffic sites.
- Watch **Domains → Blocked** to spot someone trying to scrape your feed.
