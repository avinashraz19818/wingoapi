# Plugging your own site into the lottery engine

Your platform keeps the users and the main wallet. The lottery engine runs the
games, holds a small "game wallet" per user, settles bets and pays winnings.
Money moves between the two with transfers — the same pattern your site already
uses for other third-party games.

```
  user opens WinGo      →  your backend calls PartnerLogin   →  token
  user brings ₹500 in   →  your backend calls PartnerTransfer in
  user plays            →  front-end uses the token (bets, results, history)
  user leaves           →  your backend calls PartnerTransfer out
```

## Files here

| File | What it is |
|---|---|
| `php/LotteryClient.php` | Copy this into your site. Plain PHP, no Composer. |
| `php/example-open-game.php` | Endpoint your front-end hits when the user opens the lottery. |
| `php/example-transfer-back.php` | Returns the game wallet to your main wallet. |

## 1. Get your API key

Admin panel → **Domains** → add your site's domain → copy the API key.
The key identifies your site; keep it on the **server side only**.

## 2. Wire the three calls

```php
require 'LotteryClient.php';
$lottery = new LotteryClient('https://api.devlopedwithzayro.site', 'YOUR_API_KEY');

// when the user opens the game
$session = $lottery->login($user['id'], $user['name']);
$token   = $session['token'];

// deposit into the game (orderId = your own transaction id, must be unique)
$lottery->transferIn($user['id'], 500, 'DEP-'.$txnId);

// take it back
$lottery->transferOut($user['id'], 200, 'WDR-'.$txnId);
```

Front-end, once it has the token:

```js
localStorage.setItem('ar_token', token);
// every lottery call:
fetch(apiBase + '?action=GetBalance', { headers: { Authorization: 'Bearer ' + token } })
```

## 3. Order of operations for transfers

Always move money on **your** side and the game side in this order, so a crash
can never create money:

- **In:** debit your wallet first → then `transferIn`. If `transferIn` fails,
  refund your wallet.
- **Out:** `transferOut` first → then credit your wallet. If your credit fails,
  the money is still safely in the game wallet and the next sweep picks it up.

`orderId` makes both directions idempotent: retrying the same id returns
`duplicate: true` and moves nothing.

## 4. Alternative: skip PartnerLogin entirely

If your site already issues JWTs to its users, share the signing secret and the
engine will accept those tokens directly — no backend changes at all:

```sql
UPDATE lot_domains SET player_secret = '<your jwt secret>' WHERE domain = 'your-site.com';
```

The token is only trusted when it arrives from your own domain
(Origin/Referer), and the user id is read from `externalId`, `uid`, `userId`,
`id` or `sub`.

See [`../docs/PARTNER.md`](../docs/PARTNER.md) for the full endpoint reference.
