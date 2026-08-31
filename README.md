# Lottery SaaS API

Production-ready multi-game lottery backend in **PHP 8+ / MySQL 8+** with no
framework and no Composer dependencies.

Games: **WinGo** (30s/1m/3m/5m/10m) · **TrxWinGo** (1m/3m/5m/10m) ·
**K3** (1m/3m/5m/10m) · **D5 / 5D** (1m/3m/5m/10m) · **MotoRace** (1m) — 18 games in total.

```
GET  /api/Lottery?action=GetGameList
POST /api/Lottery?action=WinGoBet      (Bearer JWT)
```

Every response uses one envelope: `{data, code, msg, msgCode, serviceTime}`.

## Highlights

| Area | What it does |
|---|---|
| **Draws** | Fetches the provider (`{DRAW_BASE_URL}/{game}/{interval}.json`, 5s timeout); falls back to a provably-fair local **HMAC-SHA256** generator. `FORCE_REMOTE_DRAW=true` disables the fallback. |
| **Issue numbers** | 17 digits — `YYYYMMDD + familyCode(3) + intervalCode(2) + sequence(4)`, e.g. `20260831100010001`. Derived from the clock, no counter table. |
| **Betting** | Per-family content validation, `stake = amount × multiplier × units`, ₹1–₹10,00,000 limits, 2% payout tax, idempotent via `request_group_key` + `request_key`. |
| **Wallet** | Locked balance mutations with an immutable ledger; every entry has a unique `entry_key` so retries and cron overlaps can never double-spend or double-pay. |
| **Settlement** | Auto-settles each round: evaluates every bet, updates `won/lost`, credits net payout, records a per-issue settlement summary. |
| **VIP** | 1 EXP per ₹1 staked; levels 0/3K/30K/400K/4M/20M; one-time backfill of historical bets. |
| **Auth** | HS256 JWT (`id`, `mobile`, `exp`) via `Authorization: Bearer`; optional MD5-of-sorted-params request signature for write endpoints; separate admin token. |
| **Admin overrides** | Per-game/per-issue forced results, plus the legacy one-shot "next round" mode. Consumed and cleared automatically. |
| **Copy trading** | Curated plans (BigSmall, Color, K3 Big, 5D A-Big, TRX Green …); subscribers get one auto-bet per round with round budgets and duplicate protection. |
| **Trends** | Missing count, open count, max continuous and current streak per option per position over the last 100 rounds. |
| **Result feed (SaaS)** | Mirror an upstream provider (or draw locally) and republish results under **your own domain** in provider-compatible URLs. Customers never see the upstream. |
| **Domain whitelist** | Only domains you whitelist can read the feed — enforced by Origin/Referer *and* per-customer API keys, with per-game plans, expiry dates, rate limits and usage counters. |
| **Results board** | Public page at `/results` showing every game section with live countdowns and the latest draws. |
| **Admin panel** | Web console at `/admin`: dashboard, live rounds with per-outcome risk exposure, one-click result overrides, users & wallets, immutable ledger, copy-trade plans, VIP, audit log. |
| **Security** | CORS allowlist, 120 req/min rate limiting, hardened headers/HSTS, prepared statements everywhere, strict input validation. |
| **Schema** | Auto-created on first request; versioned migrations in `src/Database/Migrator.php`, MySQL + SQLite dialects. |

## Quick start

```bash
cp .env.example .env          # set DB credentials + secrets
php tools/migrate.php         # create/upgrade the schema and seed the games
php -S 0.0.0.0:8080 index.php # dev server (use Nginx/Apache in production)

curl "http://localhost:8080/api/Lottery?action=GetGameList"
```

Place a bet:

```bash
TOKEN=$(php tools/token.php 1001 9876543210)
php tools/wallet.php credit 1001 1000 "test float"

curl -X POST "http://localhost:8080/api/Lottery?action=WinGoBet" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"gameCode":"WinGo_1M","betType":"color","betContent":"green","amount":100,"multiplier":2}'
```

Open the admin panel at <http://localhost:8080/admin> (login with `ADMIN_USER` /
`ADMIN_PASSWORD` from `.env`).

Run the draw/settlement/copy-trade worker:

```bash
php cron/worker.php --once      # one pass (cron)
php cron/worker.php --loop      # daemon (systemd)
```

## Tests

```bash
php tests/run.php
```

498 assertions covering issue numbering, all five rule engines, the HMAC draw
generator, provider parsing, overrides, wallet/ledger invariants, stake maths and
limits, idempotency, settlement payouts and tax, VIP levels and backfill, copy
trading, trend statistics, JWT/signature security, the full API surface and the
admin panel (session auth, risk simulation, wallet adjustments, plan CRUD, audit),
the result feed and every domain-whitelist rule.

## Layout

```
index.php               router / front controller
api/Lottery.php         player API entrypoint (all actions)
api/Admin.php           admin panel API entrypoint
api/Feed.php            public result feed (whitelisted domains only)
panel/                  admin console + public results board (static, no build step)
bootstrap.php           autoloader + runtime guards
config.php              configuration (env-driven)
schema.sql              generated MySQL reference DDL
src/
  Api/                  Kernel + LotteryController, AdminKernel + AdminController
  Auth/                 Jwt, Signature, Authenticator
  Betting/              BetService (validation, units, limits, idempotency)
  Database/             Connection, Migrator, Seeder, Tables
  Draw/                 DrawService, DrawFetcher, LocalDrawGenerator, HmacRandom
  Follow/               Copy-trading plans and subscriptions
  Games/                Registry, issue numbering/scheduling, Families/* rules
  Settlement/           SettlementService
  Stats/                TrendService
  Support/              Response, Http, Money, Validator, Security, RateLimiter…
  Vip/  Wallet/  Admin/ VIP, wallet + ledger, overrides, AdminService
cron/worker.php         draw + settle + copy-trade loop
tools/                  migrate, token, sign, wallet, dump_schema
deploy/                 nginx, apache, php-fpm, systemd, crontab, install.sh
docs/                   API.md, DEPLOY.md
```

## Documentation

- [`docs/API.md`](docs/API.md) — every endpoint, bet type, odds table and error code
- [`docs/ADMIN.md`](docs/ADMIN.md) — the admin panel: sections, admin API, hardening
- [`docs/FEED.md`](docs/FEED.md) — the result feed, upstream mirroring and the domain whitelist
- [`docs/DEPLOY.md`](docs/DEPLOY.md) — VPS setup, TLS, worker, ops and security checklist

## Configuration

All settings come from environment variables (or `.env`) — see
[`.env.example`](.env.example). The essentials:

```ini
DRAW_BASE_URL=https://draw.yourdomain.com
FORCE_REMOTE_DRAW=false
DRAW_SECRET=<32-byte random>
JWT_SECRET=<32-byte random>
SIGNATURE_SECRET=<32-byte random>
ADMIN_TOKEN=<24-byte random>
ADMIN_PASSWORD=<panel login password>
PAYOUT_TAX_RATE=0.02
RATE_LIMIT_PER_MIN=120
TIMEZONE=Asia/Kolkata
```

Games are declared in `config.php` as `{lottery, interval, sort}` tuples, so
enabling or reordering a game is a one-line change.
