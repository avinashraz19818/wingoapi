# Admin panel

A dependency-free web console for running the platform: `https://your.domain/admin`

![sections](https://img.shields.io/badge/sections-dashboard%20·%20live%20games%20·%20results%20·%20bets%20·%20users%20·%20ledger%20·%20copy%20trading%20·%20VIP%20·%20audit%20·%20system-4f8cff)

The panel is a static SPA (`panel/index.html` + `panel/assets/*`) that talks to
`/api/Admin?action=...`. There is no build step, no npm install and no CDN calls.

## Enabling it

```ini
# .env
ADMIN_PANEL_ENABLED=true
ADMIN_USER=admin
ADMIN_PASSWORD=<long random password>   # falls back to ADMIN_TOKEN when unset
ADMIN_TOKEN=<random>                    # machine access via X-Admin-Token
ADMIN_SESSION_TTL=28800                 # 8 hours
```

Open `/admin`, sign in, and the browser stores a session token (HS256, signed
with a secret derived from `JWT_SECRET`, so a *player* JWT can never be used
here). Sessions expire after `ADMIN_SESSION_TTL`; every login attempt is
throttled and logged.

> **Harden it further:** uncomment the `allow … ; deny all;` lines in
> `deploy/nginx.conf` (`location = /admin` and `location ^~ /panel/assets/`) to
> restrict the panel to your office/VPN IP ranges.

## What each section does

### Dashboard
Stake / payout / GGR / margin for today, 7-day bar series, per-game breakdown,
player balances, pending bets, active copy-trade subscriptions, queued overrides,
the latest 12 draws, and a worker health pill (red when no draw landed in 15 min).

### Live games
All 18 games with their current issue, a live countdown, whether betting is open,
how much is staked on the round right now, and any queued override.

- **Risk** opens the exposure modal: stake per selection *and* the exact payout /
  house profit for every possible outcome (10 WinGo digits, all 56 K3 dice
  combinations, 10 MotoRace champions). One click on **Force** queues that
  outcome as the result for the round.
- **Settle** sweeps and settles the game's finished rounds immediately.

### Results
Queue or cancel overrides (exact issue, or blank issue = legacy "next round"
mode), then browse draw history with bets, stake, payout and GGR per round, and
the source badge (`remote`, `local`, `override`).

### Bets
Filter by game, status, source (manual vs copy-trade), user or issue; totals for
the current filter are shown in the header. Click a user id to open their profile.

### Users
Create a user (returns a ready-to-use player JWT), search by mobile/id, block or
unblock, and open the profile modal: balances, VIP, recent bets and ledger, plus
manual **credit / debit** (goes through the same locked wallet + ledger path the
API uses) and a **Backfill VIP** button.

### Ledger
The immutable wallet ledger — direction, amount, balance before/after, reference
type and id — optionally filtered to one user. This is the reconciliation view.

### Copy trading
Create or update follow plans (validated against the family rules, so a plan can
never place an invalid bet), enable/disable them, and monitor subscriptions with
the ability to stop any of them. Deleting a plan that still has active
subscribers disables it instead, keeping history intact.

### VIP
Level thresholds with the number of players in each, plus the top players by
experience.

### Audit log
Every admin write — logins, overrides, settlements, wallet adjustments, plan
changes, VIP backfills — with actor, target, detail and IP.

### System
Version, environment, timezone, DB driver, schema version, draw provider,
`force_remote_draw`, tax rate, stake limits, rate limit, signing mode, last draw
and last settlement, plus a **Run worker pass** button (useful if the systemd
worker is down).

## API reference (`/api/Admin?action=…`)

Auth: `Authorization: Bearer <session token>` or `X-Admin-Token: <ADMIN_TOKEN>`.
Same envelope as the public API: `{data, code, msg, msgCode, serviceTime}`.

| Action | Method | Params |
|---|---|---|
| `Ping` | GET | — (public) |
| `Login` | POST | `user`, `password` (public) |
| `Dashboard` | GET | `days` (1-60) |
| `System` | GET | — |
| `Games` | GET | — |
| `Exposure` | GET | `gameCode`, optional `issueNumber` |
| `Results` | GET | `gameCode?`, `pageNo`, `pageSize` |
| `Overrides` | GET | `gameCode?` |
| `SetOverride` | POST | `gameCode`, `value`, `issueNumber?`, `mode?`, `note?` |
| `CancelOverride` | POST | `gameCode`, `issueNumber?` |
| `Settle` | POST | `gameCode`, `issueNumber?` (blank = sweep) |
| `RunWorkerPass` | POST | — |
| `Bets` | GET | `gameCode?`, `status?`, `source?`, `userId?`, `issueNumber?`, paging |
| `Users` | GET | `search?`, paging |
| `User` | GET | `userId` |
| `CreateUser` | POST | `mobile`, `nickname?`, `balance?` |
| `AdjustWallet` | POST | `userId`, `amount`, `direction` (`credit`\|`debit`), `remark?` |
| `SetUserStatus` | POST | `userId`, `status` (0/1) |
| `Ledger` | GET | `userId?`, paging |
| `Plans` | GET | — |
| `SavePlan` | POST | `planCode`, `name`, `gameCode`, `betType`, `betContent`, `minAmount`, `sort`, `state`, `description?` |
| `DeletePlan` | POST | `planCode` |
| `Follows` | GET | `status?`, paging |
| `StopFollow` | POST | `followId` |
| `Vip` | GET | `limit?` |
| `BackfillVip` | POST | `userId` |
| `AuditLog` | GET | paging |

Example:

```bash
TOKEN=$(curl -s -X POST 'https://your.domain/api/Admin?action=Login' \
  -H 'Content-Type: application/json' \
  -d '{"user":"admin","password":"…"}' | jq -r .data.token)

curl -s "https://your.domain/api/Admin?action=Exposure&gameCode=WinGo_1M" \
  -H "Authorization: Bearer $TOKEN" | jq '.data.outcomes[0:3]'

curl -s -X POST "https://your.domain/api/Admin?action=AdjustWallet" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"userId":1001,"amount":500,"direction":"credit","remark":"support refund"}'
```

## Safety notes

- Wallet adjustments and settlements reuse the production services — the same
  locking, ledger keys and idempotency guarantees apply.
- Forced results are consumed once and cleared automatically; every one is
  written to the audit log with the operator and reason.
- The panel never exposes secrets: `System` returns configuration *flags*, not
  `JWT_SECRET`, `DRAW_SECRET` or database credentials.
- Run the panel over HTTPS only, and keep `ADMIN_PASSWORD` out of shared chats.
