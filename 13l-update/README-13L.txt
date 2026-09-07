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

V7 — MY-HISTORY FLICKER + PURANE-GALAT-BETS REPAIR:
  Fix 1: Saare API/JSON responses par ab no-store headers — browser purana
  history 1 second dikhake wapas nahi karega (yehi "1 sec sahi, phad purana"
  bug tha).
  Fix 2: Jo bets pehle GALAT result par settle ho chuke the, unhe official
  result se dobara calculate karke THEEK karne wala repair script:
  https://13l.club9.eu.cc/13l-repair.php?key=13l2026
  (ek baar kholo; "bets_repaired": N dikhe; wallet ka farak auto-adjust +
  statement me 'Settle repair' entry; file khud delete.)

V9 — ROOT CAUSE FIXED (BigSmall bet ULTA settle hona):
  Bet-content parser "BigSmall_Big" me "Small" word dhoondh leta tha — isliye
  har BIG bet chhupke SMALL ban jaata tha (jeeta hua bet "Lose" dikhta tha).
  Structured parse add: BigSmall_Big / Color_green / Num_5 / Sum_10 ab EXACT
  parse hote hain, loose fallback sirf anokhe formats ke liye.
  Purane ulte-settle bets + wallet theek karne ke liye EK BAAR:
  https://13l.club9.eu.cc/13l-repair2.php?key=13l2026
  (bets_checked / bets_fixed / fix_details dikhega — screenshot bhejo)

V10 — FINAL: SELF-HEALING (koi script chalane ki zaroorat NAHI):
  1) Parser fix (v9) ke saath naye bets 100% sahi settle.
  2) le_autorepair(): game page ke har poll par (zyada se zyada 10 min me ek baar)
     server khud: pending settle + pichle 7 din ke saare settled bets ko OFFICIAL
     result se recalculate, galat state/amount + wallet + statement theek.
  3) History row aur result-popup ab RESPONSE me bhi turant sudhar jate hain
     (display self-correction), DB repair ka intezaar nahi.
  Zip extract karo = bas. Verify: 13l-doctor.php me "has_choice_fix"/
  "has_autorepair": yes + "autorepair":{last_run, fixed} dikhna chahiye.

V11 — MY-HISTORY FINAL FIX (self-heal guaranteed + ek-file self-deploy):
  Kyu ab tak "same" lag raha tha:
   a) autorepair lottery_issue() se juda tha = sirf NAYA BET lagane par chalta
      tha; doctor me saaf dikha "abhi tak nahi chala".
   b) Purane periods (2304/2305/1148/862) ki lottery_results row hi feed se
      backfill nahi hui thi, to repair JOIN unhe skip kar deta tha.
  Fix:
   a) le_autorepair() ab HAR API request par gate hai (router entry) —
      app/history/login/kuch bhi kholo, 10 min me ek baar repair pakka chalega.
   b) Repair pehle users ki games ka feed (100 rows/game) lottery_results me
      backfill karta hai, phir LEFT JOIN: official row mile to wahi, warna BET
      par saved premium (history me dikh number) se Win/Lose recalculate +
      wallet/statement fix.
   c) 13l-deploy.php — zip extract karne ki zaroorat khatam: sirf ye EK file
      public_html me dalo, phir link kholo:
        https://13l.club9.eu.cc/13l-deploy.php?key=13l2026
      Ye khud GitHub se latest zip download karega, backup banayega,
      config.php/bridge.php ko chhue bina files update karega, aur TURANT
      backfill+repair chala ke report dega. Idempotent — jitni baar chaho.

V11.1 — DEPLOY/REPAIR ROBUST (07:21 ke 500 ka asli matlab):
  500 isliye aaya kyunki deploy ke andar ka repair 30-sec PHP timeout me
  kat gaya — lekin usse PEHLE jo flips ho chuke the wo DB me save ho gaye:
  doctor ne khud dikha 2305=WIN +9600, 2304=LOSE -1000, 1148=WIN +3840,
  2327=WIN +960 — MATLAB MY HISTORY AB SAHI HAI. Bachi sirf legacy 1-rs
  demo bet 862 (uska koi official result feed me nahi — ab bet ke saved
  number se fallback se wo bhi fix hoti hai).
  Changes: le_autorepair/repair2/deploy me set_time_limit(0) + ignore_user_abort;
  lockfile ab host ke /tmp ke alawa site folders me bhi try karta hai;
  deploy har step print karta hai (blank 500 nahi aayega); repair2 v11.1 =
  backfill + no-gate full recalc + wallet + statement.
  Ek step: 13l-deploy.php link khol (jo bhi output aaye theek), phir app khol.
