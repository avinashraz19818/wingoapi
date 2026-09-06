13L555 FINAL PACKAGE (v5) — SAB SETUP KHUD
=============================================
Ab tumhe sirf 2 kaam karne hain. Bas. Koi file edit nahi karni —
bridge (official AR draw feed) aur tax sab andar se configured hai.

STEP 1 — EXTRACT
  cPanel → File Manager → public_html → 13l-update.zip upload → Extract Here
  → "overwrite/replace" puche to OK/Replace.

STEP 2 — EK LINK KHOLO (browser me)
  https://13.club9.eu.cc/13l-setup.php?key=13l2026

  Ye link khud karega:
   - purane (local formula wale) results DB se hata dega
   - OFFICIAL draw feed se taazaa results bhar dega
   - bet tax 2% set kar dega (payout se katta hai, reference site jaisa)
   - setup file ko khud delete kar dega
  "ok": true aate hi kaam khatam. Site refresh karke game dekho.

IS PACKAGE ME KYA-KYA HAI
  1) RESULT = ABHI JO REFERENCE SITE PAR DIKH RAHA HAI, Wahi. 13L555 ab
     seedha official AR draw feed (draw.ar-lottery01.com — jo tumhara "API"
     hai, tumhare wingoapi config me likha hua) se results mirror karta hai.
     Feed ek-do second late ho to engine khud 1.5s×2 wait karke official
     number leta hai; host down ho to game local draw par chalta rahega,
     feed aate hi wapas sync.
  2) PERIOD 1 PICHE + timer 0 par result + popup guaranteed + aakhri 5
     second bet lock — sab applied.
  3) MY HISTORY ka time period ke andar (1 period piche).
  4) Download bar wala DOUBLE ✕ fix — mera wala hata diya, skin ka apna ✕
     rahega. index.html is baar chhui bhi nahi gayi.
  5) Tax: admin → Lottery → "Fee" column se % badal sakte ho (default 2).
     Note: bridge ON hone par Win%/Force ka result par asar nahi (result
     official hai), Fee aur payout multipliers local hain.

VERIFY (30 second)
  WinGo 1 Min kholo → upar period number DhaniWin/reference site ke barabar,
  aur jis period ka timer 0 hoga uska number DONO sites par SAME aayega
  (0=red+violet, 5=green+violet, even=red, odd=green colors bhi same).

FILES BADLI HAIN (size se check kar lo, public_html ke andar)
  13l-setup.php                    2,719B  (ek-baar chalao, khud delete)
  api/_core/lottery_bridge.php     6,566B  (naya — official feed)
  api/_core/bridge.php                 374B  (naya — configured, chhune ki zaroorat nahi)
  api/_core/bootstrap.php         90,029B
  api/_core/lottery_engine.php    25,024B
  api/_router.php                178,475B
  api/_core/bridge.example.php       489B  (backup sample — ignore)
  css/13l-overlay.css              1,111B  (sirf layout guard)
  js/13l-overlay.js                  386B  (no-op — duplicate X bug fix)
  tools/13l-purge-results.php    1,572B   (agar kabhi phir purge karna ho;
                                            chalane ke baad file delete karna)
  users, wallet, bets, config (DB) — KUCH nahi chheda.
  Sab wapas purana chahiye: bas api/_core/bridge.php delete kar do → game
  apne local draw par aa jayega (periods phir bhi same numbering par).

V5 UPDATE — AGAR SETUP NE "ok": false DIYA THA (feed host se nahi mila):
  Isi zip ko extract karne ke baad ye ek link kholo — sab AUTOMATIC theek ho
  jayega (khud try karega: (a) official AR feed browser-headers ke saath,
  (b) agar wo fail ho to DhaniWin site ke database se uska provider API URL
  dhundh ke usse connect karega):
  https://13.club9.eu.cc/13l-fixfeed.php?key=13l2026
  "ok": true aaye to game refresh karke dekho — result ab reference se match.
  Dono fail ho to is JSON ka screenshot bhej do (host outbound blocks kar
  raha hai — hosting se "allow outgoing HTTPS" bolwana padega).

V6 UPDATE — SETTLE/RESULT CONSISTENCY FIX:
  (1) Ab local fallback result DB me save NAHI hota jab feed on hai — isliye
      kisi period ka "jaldi-bana" result history/settle ko bigad nahi sakta.
  (2) Feed sync ab purani galat rows ko BHI theek karta hai (ON DUPLICATE
      UPDATE) — deploy ke ~1 minute me purani rows official results se
      auto-correct ho jayengi (Game history gayab/blank wali shikayat ka ye
      ilaaj hai; rows 15-sec cache ke andar fix dikhne lagenge).
  (3) Popup ka early settle ab sirf tab chalega jab OFFICIAL result row pehle
      se maujood ho — isse "Win/Lose galat mark" wali race khatam.
  NOTE: jo bets v6 se PEHLE galat settle ho chuke hain (jaise demo test ke
  -10,000/+44,200 wale), unke records waise hi rahenge — ab se naye bets
  hamesha official result se hi settle honge.
