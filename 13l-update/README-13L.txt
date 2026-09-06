13L555 — v4 PACKAGE (RESULT BRIDGE + TAX + FIXES)
====================================================
(2026-09-07 — 13l repo ki apni architecture par. index.html is baar zip me
NAHI hai — wo file ab kisi ko overwrite nahi karegi.)

ZIP ME KYA HAI (public_html ke same path):
  api/_core/lottery_bridge.php   ← NAYA: result provider ka bridge
  api/_core/bridge.example.php   ← NAYA: isko copy karke bridge.php banao
  api/_core/bootstrap.php        ← issue() ab bridge-follow karta hai
  api/_core/lottery_engine.php   ← result = bridge → fallback local draw;
                                   tax model theek; history time −1 period
  api/_router.php                ← bet debit = plain stake (tax payout se),
                                   popup winAmount, bet lock, popup grace
  css/13l-overlay.css            ← sirf layout guard (pill ✕ hata diya)
  js/13l-overlay.js              ← ab no-op hai (duplicate ✕ bug fix)
  tools/13l-purge-results.php    ← ek-baar cleanup tool (neeche dekho)
  README-13L.txt

1) RESULT BRIDGE — "API wala result" 13L par (ASLI FIX)
   Ab tak 13L apne local formula (crc32) se number bana raha tha — isliye
   period same tha par result reference site se alag. Ab 13L me wahi bridge
   lag gaya hai jo DhaniWin me hai: results aapke DRAW PROVIDER API se
   mirror honge (balance/wallet/bets 100% local hi rahenge).
   Enable karne ka tareeka (2 minute):
     a) DhaniWin ke ADMIN panel me jao → wahi URL jo "lottery_upstream_url"
        me likha hai (aur key agar "lottery_upstream_key" me hai) — copy karo
     b) cPanel File Manager → public_html/api/_core/ → bridge.example.php ko
        copy karke naam do: bridge.php
     c) bridge.php kholo, do values bharo:
          'base' => 'https://WAHA-URL',   ← provider ka address
          'key'  => 'WAHI-KEY',           ← khaali bhi ho sakta hai
     d) 13L site refresh. Ab period + result DONO provider se aayenge —
        DhaniWin wala jo dikhayega, 13L555 par bhi wahi dikhega.
   Provider down ho to game rukta nahi — local draw (crc32) fallback chal
   jata hai, aur provider wapas aate hi phir se uske results bind ho jate
   hain (INSERT IGNORE, purani rows overwrite nahi hoti).

2) EK BAAR PURGE PHIR SE (zaroori!)
   Bridge ON karne ke BAAR ek baar:
     https://13l555.com/tools/13l-purge-results.php?key=13lpurge2026
   → {"ok":true,...} aate hi tools/13l-purge-results.php delete kar do.
   (Kyunki pichle kuch ghanton me results local formula se DB me save ho
   chuke hain — DB row ko priority milti hai, isliye ek baar saaf karna hai
   taaki ab har result bridge se bhare.)

3) BET TAX / FEE (ab sahi model me)
   - Bet ke waqt sirf stake katta hai (pehle stake+fee kat-ta tha — galat tha)
   - Tax jeetne par payout se katta hai: payout = (stake − fee) × rate
   - Fee % admin se set karo: /admin → "Lottery Win/Loss & Payout" → har
     game ki row me "Fee" column (2 = 2%). Abhi 0 hai = tax nahi.
   - Note: bridge ON hone par Win% aur Force mode ka koi asar nahi (result
     provider ka hota hai); Fee aur payout multipliers LOCAL hain — inhe
     reference site se match karke rakhna taaki jeet ka paisa same aaye.

4) MY HISTORY TIME — bet ka time 1 period piche dikhta hai (period ke andar).
   Ye filter/payout ke liye display-only hai; DB me real time safe.

5) DUPLICATE ✕ BUG FIX — "Download APP" bar par skin ka apna ✕ pehle se tha;
   mera injected wala hata diya gaya. css/js update ho jayenge, index.html
   bilkul nahi chhedi gayi is baar.

6) Jo cheezein PEHLE se sahi hain aur chedi nahi: 1-period lag, timer-0
   reveal/settle, last-5-sec bet lock, popup race grace, Follow OFF,
   X1 X5 X10 X20 X50 X100.

STEPS (CRONOLOGY MAT BHOOLNA):
  1) public_html me zip extract karo (overwrite)
  2) bridge.php banao + values bharo (upar section 1)
  3) Purge ek baar chalao (section 2)
  4) Admin → Lottery me Fee=2 (ya jo chahiye) set karo
  5) Browser refresh → test

VERIFY:
  [1] https://13l555.com/webapi/kv/issue/WinGo_1Min → JSON me period number
      reference site ke current period se match kare
  [2] Timer 0 par result + popup — dono sites par SAME number
  [3] My history: time period ke andar
  [4] Download bar me sirf EK ✕
  [5] 100₹ bet, fee 2%, number 9x jeeto → payout 882₹ aana chahiye
      ((100−2)×9) — wallet check kar lena

ROLLBACK: sirf bridge.php delete kar do → engine wapas local draw par;
files ke .bak se restore kar sakte ho (DB me koi schema change nahi).
