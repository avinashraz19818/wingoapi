# Partner integration (third-game style)

Use this when the site running the game **already has its own users and
wallet** — exactly like plugging in any third-party game provider. Your
platform stays the source of truth for accounts and money; this API is the
lottery engine.

```
   your site (users, main wallet)              this API (lottery engine)
   ────────────────────────────                ─────────────────────────
   user opens WinGo                 ──▶  PartnerLogin      → player token
   user transfers ₹500 into game    ──▶  PartnerTransfer   → game wallet +500
   user bets / results / payouts    ──▶  normal game API with that token
   user transfers back              ──▶  PartnerTransfer out
```

Everything is keyed to the **API key of a whitelisted domain** (admin panel →
Domains). Two partners can both have a user `1001` — ids are namespaced per
domain and never collide.

## 1. Log the user in (server-to-server)

```bash
curl -X POST "https://api.example.com/api/Lottery?action=PartnerLogin" \
  -H "X-Api-Key: <domain api key>" -H 'Content-Type: application/json' \
  -d '{"externalUserId":"1001","nickname":"Ravi"}'
```

```json
{ "data": { "token": "eyJhbGciOi…", "accessToken": "eyJhbGciOi…",
            "userId": 42, "externalUserId": "1001",
            "balance": "0.00", "vipLevel": 0 },
  "code": 0, "msg": "success", "msgCode": "SUCCESS" }
```

Hand `data.token` to your front-end; it then calls the ordinary endpoints
(`GetBalance`, `WinGoBet`, `GetRecordPage`, …) with
`Authorization: Bearer <token>`.

`externalUserId` is also accepted as `userId`, `uid`, `memberId` or `account`.

## 2. Move money in and out

```bash
# deposit into the game wallet
curl -X POST "https://api.example.com/api/Lottery?action=PartnerTransfer" \
  -H "X-Api-Key: <key>" -H 'Content-Type: application/json' \
  -d '{"externalUserId":"1001","amount":500,"direction":"in","orderId":"TXN-8891"}'

# withdraw back to your platform
  -d '{"externalUserId":"1001","amount":200,"direction":"out","orderId":"TXN-8892"}'
```

```json
{ "data": { "orderId": "TXN-8891", "direction": "in", "amount": "500.00",
            "applied": true, "duplicate": false, "balance": "500.00" } }
```

- `orderId` makes transfers **idempotent** — replaying it returns
  `duplicate: true` and moves nothing, so a timeout retry is always safe.
- An over-withdrawal returns `INSUFFICIENT_BALANCE`; nothing is deducted.
- Every movement lands in the immutable ledger as `transfer_in` / `transfer_out`.

## 3. Read state

```bash
curl "https://api.example.com/api/Lottery?action=PartnerBalance&externalUserId=1001" -H "X-Api-Key: <key>"
curl "https://api.example.com/api/Lottery?action=PartnerBets&externalUserId=1001&pageSize=20" -H "X-Api-Key: <key>"
```

## 4. Identifying the player accurately

Three ways, in the order the engine tries them:

1. **`X-Player-Id` + your API key** (best). Your backend already knows who is
   logged in, so it just says so. Nothing can be misread.
2. **A JWT signed with your shared `player_secret`**, sent from your domain.
3. **Token introspection** — the engine asks your own "who am I" endpoint.

> ⚠️ Many platforms answer their user endpoint with a *default* user when the
> token is unknown. The engine probes for exactly that before trusting the
> answer, and refuses introspection for such an endpoint — otherwise every
> visitor would map onto the same player and share one balance. If you see
> `partner token endpoint does not discriminate` in the log, install the site
> bridge (it sends `X-Player-Id`) or make the endpoint reject bad tokens.

## 5. Optional: keep using your own tokens

If you would rather not call `PartnerLogin`, share an HS256 secret and we will
accept the tokens your site already issues:

```sql
UPDATE lot_domains SET player_secret = '<your hs256 secret>' WHERE domain = 'your-site.com';
```

Then a request with `Authorization: Bearer <your token>` **sent from your
domain** (Origin/Referer must match) is mapped to the matching local player.
The user id is read from `externalId`, `uid`, `userId`, `id` or `sub`.

The same token presented from any other origin is rejected, and a token signed
with the wrong secret never authenticates.

## Endpoint summary

| Action | Method | Auth | Params |
|---|---|---|---|
| `PartnerLogin` | POST | `X-Api-Key` | `externalUserId`, `nickname?`, `mobile?` |
| `PartnerTransfer` | POST | `X-Api-Key` | `externalUserId`, `amount`, `direction` (`in`\|`out`), `orderId` |
| `PartnerBalance` | GET | `X-Api-Key` | `externalUserId` |
| `PartnerBets` | GET | `X-Api-Key` | `externalUserId`, paging |

Errors: `API_KEY_REQUIRED` (401), `DOMAIN_NOT_ALLOWED` (403 — unknown/disabled/
expired key), `VALIDATION_ERROR`, `INSUFFICIENT_BALANCE`.

## Checklist for a new partner

1. Admin panel → **Domains** → add their domain, copy the API key.
2. They call `PartnerLogin` from their backend when a user opens the game.
3. They call `PartnerTransfer` on deposit/withdraw with a unique `orderId`.
4. Their front-end uses the returned token for all game calls.
5. Watch traffic and blocked attempts per domain in the panel.
