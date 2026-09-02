# Lottery API reference

Base URL: `https://api.example.com/api/Lottery`

The action can be given three ways — all equivalent:

```
GET  /api/Lottery?action=GetGameList          query string
GET  /api/Lottery/GetGameList                 path style
GET  /api/webapi/GetGameList                  legacy front-end base
POST /api/Lottery            {"action":"Login", …}   JSON body
```

## Response envelope

Every response — success or failure — uses the same shape:

```json
{
  "data": { },
  "code": 0,
  "msg": "success",
  "msgCode": "SUCCESS",
  "serviceTime": 1788000000123
}
```

| Field | Meaning |
|---|---|
| `data` | Payload (object, or `null` on error) |
| `code` | `0` = success. Non-zero = failure (table below) |
| `msg` | Human readable message |
| `msgCode` | Stable machine-readable code |
| `serviceTime` | Server time in epoch milliseconds (Asia/Kolkata) |

### Error codes

| `code` | `msgCode` | HTTP | Meaning |
|---|---|---|---|
| 1001 | `VALIDATION_ERROR` | 200 | Bad or missing parameter |
| 1002 | `AUTH_REQUIRED` | 401 | Missing/invalid/expired JWT, or admin token |
| 1003 | `INVALID_SIGNATURE` | 401 | Signature missing, stale or mismatched |
| 1004 | `RATE_LIMITED` | 429 | More than 120 requests/minute from one IP |
| 1005 | `NOT_FOUND` | 404 | Unknown action / game / record |
| 1006 | `INSUFFICIENT_BALANCE` | 200 | Wallet cannot cover the stake |
| 1007 | `BETTING_CLOSED` | 200 | Round locked or already drawn |
| 1008 | `CONFLICT` | 409 | Duplicate subscription / ledger conflict |
| 1500 | `SERVER_ERROR` | 500 | Unhandled failure (details only when `APP_DEBUG=true`) |

## Authentication

### Get a token (what your front-end calls first)

| Action | Method | Body | Returns |
|---|---|---|---|
| `Register` | POST | `mobile`, `password`, `nickname?` | `token`, `userId`, `balance`, `vipLevel` |
| `Login` | POST | `mobile`, `password` | same |
| `GetUserInfo` | GET | — (Bearer) | profile + balance + VIP |
| `ChangePassword` | POST | `oldPassword`, `newPassword` (Bearer) | `changed` |
| `RefreshToken` | POST | — (Bearer) | a fresh token |
| `Logout` | POST | — | `loggedOut` (drop the token client-side) |

```bash
curl -X POST "https://api.example.com/api/Lottery?action=Login" \
  -H 'Content-Type: application/json' \
  -d '{"mobile":"9876543210","password":"secret123"}'
```

```json
{ "data": { "token": "eyJhbGciOi…", "tokenType": "Bearer", "expiresIn": 86400,
            "userId": 1001, "mobile": "98******10", "balance": "0.00", "vipLevel": 0 },
  "code": 0, "msg": "success", "msgCode": "SUCCESS", "serviceTime": 1788000000123 }
```

The same token is repeated as `accessToken`, `userToken` and `jwt`, and the id
as `uid`/`id`, so most stock front-ends find it without changes. Credentials are
accepted under the usual aliases too — `mobile|phone|username|account` and
`password|pwd|passWord|loginPwd`.

Store `data.token` (e.g. `localStorage.setItem('ar_token', token)`) and send it on
every authenticated call. Passwords are bcrypt hashed; login answers with the
same message for "unknown mobile" and "wrong password" so accounts cannot be
enumerated.

> Sending `Authorization: Bearer null` / `Bearer undefined` (common before the
> user logs in) is treated as *no token*: public endpoints keep working and
> protected ones answer `401 AUTH_REQUIRED` instead of failing oddly.

### Using the token

The token is read from any of these (first match wins):

```
Authorization: Bearer <jwt>      ← preferred
Authorization: <jwt>
Token / X-Token / X-Access-Token / Auth / X-Auth-Token: <jwt>
?token= / ?access_token= / ?ar_token=
```

### Debugging a front-end: `GET ?action=Whoami` *(public)*

```json
{ "data": { "tokenReceived": true, "tokenSources": ["Authorization"],
            "tokenPreview": "eyJhbGciOi…", "valid": true, "userId": 1001,
            "expiresAt": "2026-09-02 06:45:17",
            "hint": "Token is valid — authenticated endpoints will work." } }
```

If a call 401s, hit `Whoami` with the exact same headers: it says whether a
token arrived, where it came from, and why it was rejected.

Protected endpoints require a **HS256 JWT** in the `Authorization` header:

```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

Payload contract: `{"id": 1001, "mobile": "9876543210", "exp": 1788086400}`.
The token is signed with `JWT_SECRET`; `alg: none` and algorithm confusion are rejected.
Users and wallets are provisioned automatically on the first authenticated call.

Issue a token for testing: `php tools/token.php 1001 9876543210`.

### Request signature (write endpoints)

When `REQUIRE_SIGNATURE=true`, every POST must carry `timestamp` and `signature`:

1. take all request parameters except `signature`
2. drop empty values and `ksort()` the keys
3. join as `k1=v1&k2=v2...` and append `&key=<SIGNATURE_SECRET>`
4. `signature = strtoupper(md5(payload))`

`timestamp` (unix seconds) must be within `SIGNATURE_TTL` (default 300s).
Helper: `php tools/sign.php action=WinGoBet gameCode=WinGo_1M amount=10`.

### Admin endpoints

Admin actions additionally require `X-Admin-Token: <ADMIN_TOKEN>`.

## Games

| Family | Intervals | Family code | Result |
|---|---|---|---|
| `WinGo` | 30S, 1M, 3M, 5M, 10M | 100 | digit 0-9 |
| `TrxWinGo` | 1M, 3M, 5M, 10M | 200 | digit 0-9 derived from a TRON block hash |
| `K3` | 1M, 3M, 5M, 10M | 300 | three dice 1-6 |
| `D5` (alias `5D`) | 1M, 3M, 5M, 10M | 400 | five digits A-E |
| `MotoRace` | 1M | 500 | ranking of riders 1-10 |

Game code = `Family_Interval`, e.g. `WinGo_1M`, `K3_5M`, `D5_10M`, `MotoRace_1M`.

### Issue numbers

17 digits: `YYYYMMDD` + `familyCode(3)` + `intervalCode(2)` + `sequence(4)`

```
20260831 100 01 0001   ->  WinGo_1M, 31 Aug 2026, first round of the day
```

Interval codes: `30S=00`, `1M=01`, `3M=03`, `5M=05`, `10M=10`.
The sequence is the 1-based index of the round inside the local (IST) day, so
issue numbers are fully derivable from the clock.

## Bet types and odds

Odds are the **gross** multiplier; a 2% payout tax is deducted from winnings.

### WinGo / TrxWinGo
| betType | betContent | Odds |
|---|---|---|
| `number` | `0`-`9` (comma separated for multiple) | 9 |
| `color` | `green`, `red` | 2 (1.5 when the digit is 0 or 5) |
| `color` | `violet` | 4.5 (wins on 0 and 5) |
| `size` | `big` (5-9), `small` (0-4) | 2 |
| `parity` | `odd`, `even` | 2 |

### K3
| betType | betContent | Odds |
|---|---|---|
| `total` | `3`-`18` | 3/18 207.36 · 4/17 69.12 · 5/16 34.56 · 6/15 20.74 · 7/14 13.83 · 8/13 9.88 · 9/12 8.30 · 10/11 7.68 |
| `size` | `big` (11-18), `small` (3-10) | 2 |
| `parity` | `odd`, `even` | 2 |
| `triple_any` | `any` | 34.56 |
| `triple_exact` | `1`-`6` | 207.36 |
| `pair` | `1`-`6` | 13.83 |
| `two_different` | `1:2` | 6.91 |
| `three_different` | `1:2:3` | 34.56 |

### D5 / 5D
Content is `position:option`, position ∈ `a b c d e sum`.

| betType | betContent | Odds |
|---|---|---|
| `number` | `a:7` (not valid for `sum`) | 9 |
| `size` | `a:big`, `sum:small` (digit ≥5 is big; sum ≥23 is big) | 2 |
| `parity` | `c:odd`, `sum:even` | 2 |

### MotoRace
| betType | betContent | Odds |
|---|---|---|
| `champion` | `1`-`10` | 9.4 |
| `podium` | `1`-`10` (finishes top 3) | 3.1 |
| `size` | `big` (6-10), `small` (1-5) | 2 |
| `parity` | `odd`, `even` | 2 |

### Stake maths

```
units = number of distinct selections in betContent
stake = amount x multiplier x units          (min ₹1, max ₹10,00,000)
gross = SUM(amount x multiplier x odds) over winning selections
net   = gross - gross x 0.02                 (credited to the wallet)
```

---

# Endpoints

## `GET ?action=GetGameList`
Grouped catalogue with live issue, rates and state. *(public)*

```bash
curl "https://api.example.com/api/Lottery?action=GetGameList"
```

```json
{
  "data": {
    "serverTime": "2026-08-31 12:00:10",
    "timezone": "Asia/Kolkata",
    "groups": [{
      "lottery": "WinGo",
      "name": "Win Go",
      "sort": 1,
      "state": 1,
      "intervals": [{
        "gameCode": "WinGo_30S",
        "interval": "30S",
        "intervalSeconds": 30,
        "lockSeconds": 5,
        "dailyIssues": 2880,
        "state": 1,
        "currentIssue": {
          "issueNumber": "20260831100001441",
          "startTime": "2026-08-31 12:00:00",
          "endTime": "2026-08-31 12:00:30",
          "remaining": 20,
          "bettingOpen": true
        },
        "rates": { "number": {"odds": 9}, "color": {"odds": 2}, "size": {"odds": 2} }
      }]
    }]
  },
  "code": 0, "msg": "success", "msgCode": "SUCCESS", "serviceTime": 1788000000123
}
```

## `GET ?action=GetGameInfo&gameCode=WinGo_1M`
Rates, bet types, `betScopes`, `multiples`, stake limits, current + next issue. *(public)*

## `GET ?action=GetGameIssue&gameCode=WinGo_1M`
Current and next issue with countdown. *(public)*

## `POST ?action=WinGoBet` *(auth)*
Aliases: `GameBet`, `PlaceBet` — all families use the same handler.

| Param | Required | Notes |
|---|---|---|
| `gameCode` | yes | e.g. `WinGo_1M` |
| `betType` | yes | see the tables above |
| `betContent` | yes | comma separated selections |
| `amount` | yes | per-unit stake |
| `multiplier` | no | default 1 |
| `issueNumber` | no | must equal the open issue if supplied |
| `requestGroupKey` / `requestKey` | no | idempotency pair (SHA256 recommended) |

```bash
curl -X POST "https://api.example.com/api/Lottery?action=WinGoBet" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"gameCode":"WinGo_1M","betType":"color","betContent":"green",
       "amount":100,"multiplier":2,
       "requestGroupKey":"a1b2c3","requestKey":"9f86d081884c7d659a2f..."}'
```

```json
{
  "data": {
    "betId": 4711,
    "betNo": "20260831120010A1B2C3D4E5",
    "accepted": true,
    "duplicate": false,
    "balance": "9800.00",
    "stake": "200.00",
    "units": 1,
    "odds": 2,
    "potentialPayout": "392.00",
    "gameCode": "WinGo_1M",
    "issueNumber": "20260831100010721",
    "betType": "color",
    "betContent": "green",
    "vipExperienceAdded": "200.00"
  },
  "code": 0, "msg": "success", "msgCode": "SUCCESS", "serviceTime": 1788000000123
}
```

Replaying the same `requestGroupKey` + `requestKey` returns the original bet with
`"duplicate": true` and does not charge the wallet again.

## Result publication window (`ISSUE_OFFSET`)

A round is **drawn** and **settled** the moment it closes, but `ISSUE_OFFSET`
controls when it becomes **visible**:

| `ISSUE_OFFSET` | while round N is live, the newest published result is |
|---|---|
| `0`  | `N-1` — standard |
| `-1` | `N-2` — result one period behind |
| `-2` | `N-3` — two periods behind |

The window is enforced on every public read path — `GetHistoryIssuePage`,
`GetTrendStatistics`, `GetGameIssue.lastIssue`, `GetResult` /
`GetWinTheLotteryResult` on all three surfaces (Lottery API, feed,
`/api/compat`) — and `totalCount` / `totalPage` shrink with it, so a client
paging to the end cannot find the held-back round either.

What it does **not** change:

- the live issue number and the countdown,
- draw resolution (upstream / override / local generator),
- settlement and wallet credit,
- the caller's own records (`GetRecordPage`, `GetWinLossResult`) and the admin
  panel — those always show the truth.

`GetGameIssue` reports the setting as `publicationLag`, and Admin →
`FeedInfo` returns it under `resultLag`.

## `GET ?action=GetHistoryIssuePage&gameCode=WinGo_1M&pageNo=1&pageSize=10`
Drawn results, newest first, limited to the publication window above. Finished
rounds are drawn and settled on demand. *(public)*

## `GET ?action=GetRecordPage&gameCode=WinGo_1M&pageNo=1&pageSize=10` *(auth)*
The caller's bets (omit `gameCode` for all games), including status and payout.

## `GET ?action=GetTrendStatistics&gameCode=WinGo_1M&window=100` *(public)*
Per position and option: `missing`, `openCount`, `maxContinuous`, `currentStreak`.

```json
{ "data": { "gameCode": "WinGo_1M", "window": 100, "rounds": 100,
  "positions": { "number": [ {"value":"0","missing":7,"openCount":11,"maxContinuous":2,"currentStreak":0} ] } } }
```

## `GET ?action=GetBalance` *(auth)*
Balance, frozen, lifetime stake/payout and VIP status.

## `GET ?action=GetWalletLedger&pageNo=1&pageSize=20` *(auth)*
Immutable ledger rows with `balance_before` / `balance_after`.

## `GET ?action=GetWinLossResult&gameCode=WinGo_1M&issueNumber=...` *(auth)*
Settles the issue if needed, then returns the result plus the caller's stake,
payout, profit and per-bet breakdown.

## `GET ?action=GetFollowPlanList[&gameCode=WinGo_1M]` *(public)*
Predefined copy-trading plans (BigSmall, Color, K3 Big, 5D A-Big, TRX Green, …).

## `POST ?action=AddFollowRecord` *(auth)*
`planCode` (required), `amount`, `multiplier`, `rounds` (0 = unlimited), `stopLoss`.
The worker then places one bet per issue until the budget is used, the plan is
stopped, or a bet fails (e.g. insufficient funds — the plan stops automatically).

## `POST ?action=StopFollowRecord` *(auth)*
`followId` or `planCode`.

## `GET ?action=GetMyFollowRecords` *(auth)*

## `GET ?action=GetVipInfo` *(auth)*
Experience, level, next threshold and the full level table.

| Level | Experience |
|---|---|
| 0 | 0 |
| 1 | 3,000 |
| 2 | 30,000 |
| 3 | 400,000 |
| 4 | 4,000,000 |
| 5 | 20,000,000 |

## `POST ?action=BackfillVipExperience` *(auth)*
One-time import of experience from bets placed before VIP tracking existed.

## Admin *(auth + `X-Admin-Token`)*

| Action | Method | Purpose |
|---|---|---|
| `SetResultOverride` | POST | Force a result. `gameCode`, `value`, optional `issueNumber` (omit for the legacy one-shot "next round" override), `mode` (`oneshot`\|`legacy`), `note` |
| `CancelResultOverride` | POST | Remove a pending override |
| `ListResultOverrides` | GET | Pending overrides |
| `SettleIssue` | POST | Force draw + settlement of one issue (or sweep the game) |

Override values: WinGo/TRX `"7"` · K3 `"1,3,6"` · D5 `"12345"` · MotoRace `"4"` or a
full 10-rider ranking. Overrides beat the provider and are cleared after use.

## `GET ?action=Health` *(public)*
Version, schema version, DB driver, server time, game count.
