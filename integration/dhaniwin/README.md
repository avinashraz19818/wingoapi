# Lottery engine bridge

This site can serve its lottery games from an external engine instead of the
local generator in `api/_bootstrap.php`. Nothing in the front-end changes: the
same URLs, the same JSON, the same login — only the numbers, the bets and the
payouts come from the engine.

## Switch it on

Admin panel → **Settings** → add two settings:

| Key | Value |
|---|---|
| `lottery_upstream_url` | `https://api.devlopedwithzayro.site/api/Compat` |
| `lottery_upstream_key` | the API key for this domain (engine panel → Domains) |

Optional:

| Key | Value | Meaning |
|---|---|---|
| `lottery_upstream_wallet` | `1` | game wallet lives on the engine (transfers move real money there). `0` keeps the local `game_balance`. |

**To go back to the local behaviour, clear `lottery_upstream_url`.** No code
change, no deploy.

## What now comes from the engine

- `/webapi/kv/issue/<game>` — current round + countdown
- `/<Family>/<Game>/GetHistoryIssuePage.json` — results
- `/api/Lottery/*` — game list, odds, bets, records, win/loss, trend, balance
- `/api/ThirdGame/Transfer` and `GetARGameBalance` — when
  `lottery_upstream_wallet` is on

Everything else (login, recharge, withdraw, activities, agents) stays local and
untouched.

## Prerequisites on the engine side

1. This domain must be whitelisted (engine panel → **Domains**), which is where
   the API key comes from.
2. The engine must be able to resolve this site's player tokens:

```bash
php tools/domain.php check dhaniwin.club9.eu.cc https://dhaniwin.club9.eu.cc/api/User/GetUserInfo POST
php tools/domain.php test  dhaniwin.club9.eu.cc <a real token from localStorage.ar_token>
```

## Files

| File | Change |
|---|---|
| `api/_lottery_upstream.php` | new — the whole bridge |
| `api/_bootstrap.php` | 3 lines: load the bridge if present |
| `api/_router.php` | 6 lines: try the engine before the local handler |
| `api/_draw_router.php` | 3 hooks: issue + history from the engine |

## Safety

- If the engine is unreachable the bridge returns `null` and the **local
  implementation answers as before**, so the site never goes dark.
- Wallet transfers debit this site first and only credit the engine after it
  confirms; a failure refunds automatically.
- The player's own token is forwarded; the engine verifies it by calling this
  site's `/api/User/GetUserInfo`.
