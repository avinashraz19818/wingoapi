# Running an existing game front-end on this engine

Some operators already have a complete platform — users, wallet, deposits, a
compiled Vue front-end — and only want the **lottery** part to come from here.
The compatibility endpoint makes that a drop-in change: the client keeps its UI
and its login, while this platform provides the rounds, the odds, the bets, the
settlement and the payouts.

```
their front-end  ──▶  their domain (proxy)  ──▶  /api/Compat  ──▶  this engine
   unchanged            one small PHP file        their dialect      our results
```

## What the compatibility endpoint changes

| | Player API (`/api/Lottery`) | Compat API (`/api/Compat`) |
|---|---|---|
| Envelope | `{code:0, msg:"success", msgCode:"SUCCESS"}` | `{code:0, msg:"Succeed", msgCode:0}` |
| Bet content | `betType=color`, `betContent=green` | `betContent=Color_green` |
| Results | `{number:7, colors:[…], size:"big"}` | `{number:"7", premium:"7", colour:"green", openCode:"7"}` |
| Errors | `msgCode:"INSUFFICIENT_BALANCE"` | `msgCode:142`, `code:-1` |
| Auth | our JWT | the site's own token (introspected) |

Both surfaces share the same engine underneath, so a round settles identically
whichever one placed the bet.

## Bet content dialect

| Client sends | Engine bet |
|---|---|
| `Num_5`, `Num_1,2,3` | number 5 / 1,2,3 |
| `Color_green` \| `Color_violet` | colour |
| `BigSmall_H` \| `BigSmall_big` \| `BigSmall_L` | size big/small |
| `OddEven_O` \| `OddEven_E` | parity |
| `SumNum_10` (K3) | total 10 |
| `SumBigSmall_H`, `SumOddEven_O` (K3) | sum size / parity |
| `NumSame3All_3TT`, `NumSame3_4`, `NumSame2_3` | any triple / exact triple / pair |
| `NumDiff2_2_5`, `NumDiff3_1_2_3` | two / three different |
| `FirstNum_7` … `FifthOddEven_E` (5D) | position A-E digit / size / parity |
| `SumBigSmall_H` (5D) | sum size |
| `FirstNum_7` (MotoRace) | champion |
| `SecondNum_3`, `ThirdNum_9` (MotoRace) | podium |

`betContent` may also be a JSON array (`["Num_1","Num_2"]`) — each selection
becomes its own bet, exactly as the client expects when it charges
`amount × multiple × selections`.

## Endpoints served

`GetGameList` · `GetGameInfo` · `GetGameIssue` · `GetHistoryIssuePage` ·
`GetNoaverageEmerdList` · `GetTrendStatistics` · `GetBalance` · `WinGoBet` /
`K3Bet` / `D5Bet` / `MotoRaceBet` / `TrxWinGoBet` (anything ending in `Bet`) ·
`GetRecordPage` · `GetWinLossResult` · `GetBetLimit` · `GetGameIntroduce` ·
`GetDragonList` · `GetWingoLiveUrl` · follow endpoints (empty pages).

## Installing on the site

1. **Whitelist the domain** in the admin panel → Domains, copy the API key.
2. **Token check URL** so the engine can resolve the site's own tokens:

```bash
php tools/domain.php check their-site.com https://their-site.com/api/User/GetUserInfo POST
php tools/domain.php test  their-site.com <a real player token>
```

3. Copy `integration/php/lottery-proxy.php` into the site root, set
   `LOTTERY_BASE` and `LOTTERY_KEY`.
4. Add the rewrites **above** the site's own `/api/` rules:

```apache
RewriteRule ^api/Lottery/(.*)$ lottery-proxy.php [QSA,L]
RewriteRule ^webapi/(kv|v)/issue/([^/]+)$ lottery-proxy.php [QSA,L]
RewriteRule ^(WinGo|K3|D5|MotoRace|TrxWinGo)/(.+\.json)$ lottery-proxy.php [QSA,L]
```

5. Fund the game wallet — either per user with `PartnerTransfer` (see
   [PARTNER.md](PARTNER.md)) or from the admin panel.

## Verifying

```bash
curl -s "https://their-site.com/webapi/kv/issue/WinGo_30S"
curl -s "https://their-site.com/WinGo/WinGo_30S/GetHistoryIssuePage.json" | head -c 300
curl -s "https://their-site.com/api/Lottery/GetBalance" -H "Authorization: Bearer <player token>"
```

The issue numbers in all three must match, and `GetBalance` must return the
game wallet rather than `401`.

## Notes

- Game codes are accepted in every spelling the clients use: `WinGo_1M`,
  `WinGo_1Min`, `5D_1Min`, `MotoRace_1Min`, `WinGo30S`.
- One client bet with N selections becomes N engine bets, so each selection
  settles (and refunds) independently. The record page therefore shows one row
  per selection.
- Failures use the client's own codes: `142` insufficient balance, `405`
  betting closed, `315` login required, `401` bad input.
