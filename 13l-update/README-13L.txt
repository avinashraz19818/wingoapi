13L — WINGO 1-PERIOD LAG PACKAGE
=================================
(2026-09-06 — 13l repo ke ACTUAL architecture par bani hai: api/_router.php +
api/_core/. Purana dhaniwin-update.zip iss site ke liye galat structure wala
tha — ye uski jagah ka sahi package hai.)

ZIP ME KYA HAI (7 files, sab public_html ke same path par):
  api/_core/bootstrap.php    ← lottery_issue(): ab 1 period piche + isLocked
  api/_core/lottery_engine.php ← result gate, settle-force, history time shift
  api/_router.php            ← history/trend boundary reveal, bet lock, popup grace
  api/_draw_router.php       ← /webapi/.../issue history aliases ke liye same fix
  index.html                 ← AAPKI original file + sirf 2 lines (overlay loader)
  css/13l-overlay.css        ← mobile layout guard (clipping band)
  js/13l-overlay.js          ← Add-to-Desktop pill me ✕
  README-13L.txt             ← ye file

IS PACKAGE ME KYA FIX HUA:
  1) WINGO 1 PERIOD PICHE — screen par period number live se 1 piche chalta
     hai, lekin countdown/timer apni asli boundary par hi 0 hota hai.
  2) RESULT TIMER 0 PAR — kisi period ka result tab tak generate bhi nahi
     hota jab tak uska on-screen timer 0 na ho (history/trend me bhi result
     sirf boundary par dikhta hai — pehle se leak rok).
  3) BET LAST 5 SEC LOCK — period ke aakhri 5 second me naya bet server se
     reject ("Period locked") taaki result ke saath bet ka overlap na ho.
  4) WIN/LOSE POPUP GUARANTEED — phone ki ghadi 1-6 second aage ho to bhi
     popup result turant dikhata hai (result row persist ho jata hai, isliye
     double result ka koi chance nahi — boundary par wahi result settle hoga).
  5) MY-HISTORY KA TIME — bet ka dikhne wala time 1 period piche (30S game
     me −30s, 1M me −60s, 5M me −5min) — period ke andar ka time dikhega.
     DB me real time safe hai.
  6) LAYOUT GUARD + PILL ✕ — phone par balls/multiplier cut nahi honge;
     desktop-install pill me close button.

JO PEHLE SE SAHI THA (chheda nahi):
  ✓ Follow Strategy OFF (handle_lottery_user_info me isOpenFollow:false tha)
  ✓ Multiplier row X1 X5 X10 X20 X50 X100 (betMultiples sahi tha)
  ✓ Pending bet state (state=2, result ke baad settle)

STEPS:
------
1. cPanel → File Manager → public_html
2. Backup: api/_router.php, api/_core/bootstrap.php, api/_core/lottery_engine.php,
   api/_draw_router.php, index.html — har ek ka .bak bana lo
3. Zip upload → public_html me Extract → overwrite confirm karo
4. Site khol ke Ctrl+Shift+R (js/css par no-cache headers already lagi hain)

NOTE: api/_bootstrap.php (175KB wali file jo pichle zip se aayi thi) 13l me
KAHI SE REQUIRE NAHI HOTI — dead file hai, game isse nahi chalta. Ise waise hi
rehne do ya delete kar do — dono safe. Ye zip ise touch nahi karti.
DB/config (api/_core/config.php) — bilkul nahi badla, users/wallet/records
sab waise ke waise.

VERIFY (3 min):
---------------
[1] Browser me kholo: https://yourdomain.com/webapi/kv/issue/WinGo_30S
    → "issueNumber" live se 1 period piche hona chahiye, "endTime" sahi
      boundary par, countdown normal gine.
[2] WinGo game kholo → period number upar wale se match kare → 1-2 min dekho
    → timer 0 hote hi result list me sabse upar wo period aa jaye.
[3] Bet lagao → My History me pending → timer 0 par result + popup dono.
    Aakhri 5 second me bet karke dekho → "Period locked" aana chahiye.
[4] My History row ka time period ki window ke andar dikhe.
[5] Phone screen par X1..X100 chips aur balls edge se kate nahi.

ROLLBACK: chaaron .bak files wapas rename kar do; css/13l-overlay.css aur
js/13l-overlay.js delete kar do (index.html.bak restore ho jayega to wo bhi
clean). Koi DB change nahi hai, isliye rollback 100% safe hai.
