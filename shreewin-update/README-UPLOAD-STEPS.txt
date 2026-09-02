SHREEWIN - 1 PERIOD PICHE (RESULT LAG) UPDATE  v3
==================================================
v3 = v2 + 2 fixes:
  A) BET SETTLEMENT AB TIMER-END PAR (My History me turant settle hona band)
  B) BET-PANEL CSS GUARD (number balls / Random X1..X100 / game tabs ka
     screen se katna fix)

FILES AUR UNKE CPANEL TARGET PATH (4 files):
--------------------------------------------
1) saas_lottery/bootstrap_live_v4.php
   -> public_html/saas_lottery/bootstrap_live_v4.php  (replace)
   Engine: 1-period lag, timer-end reveal gate, AUR ab settlement bhi isi
   reveal-gate se chalta hai:
   - Bet lagane par result chahe server ke paas aa chuka ho, bet PENDING
     rahega jab tak screen par us period ka timer khatam nahi hota.
   - Timer ke 0 hote hi: result history me + payout balance me — ek hi
     request cycle me (upstream slow bhi ho to timer end par hi settle).
   - period_lag=0 par ye gate = live behaviour (turant settle) — unchanged.
2) saas_lottery/config_live_v4.php
   -> public_html/saas_lottery/config_live_v4.php     (replace)
   'period_lag' => 1  (0 = live, 2 = 2 period piche)
3) draw-live-v4/index.php
   -> public_html/draw-live-v4/index.php              (replace)
   Boundary-wait window widened: timer ke aas-paas wali history request hold
   hoti hai aur result ready hote hi wahi request jawab deti hai.
4) assets/css/shreewin-wheel500-hotfix.css
   -> public_html/assets/css/shreewin-wheel500-hotfix.css (replace; PURI file
   — isme pehle se maujood wheel-hotfix rules bhi hain, isliye sirf append
   mat karna, poori file hi copy karna)
   Kya fix karta hai:
   - html rem base = min(10vw, 40px) !important — poora game 10rem grid hai,
     ye lock karta hai ki grid kabhi viewport se bada na ho. Browser zoom,
     flexible.js miss hona, ya old shell — kis bhi wajah se balls row
     (0-9), multiplier strip (Random X1 X5 X10 X20 X50 X100) aur game tabs
   - .ramd chips ab wrap karte hain (bahut narrow phone ya bhaasha labels par
     screen se bahar jaane ke bajaye agli line)
   - .select tab strip ko symmetric padding — edge-clipping khatam
   - Colors/skin/animation CSS ko chhua nahi — sirf sizing guard

ZIP EXTRACT (recommended):
--------------------------
shreewin-update.zip -> public_html me upload -> right-click -> Extract Now.
Andar folder structure same hai, files apni jagah overwrite ho jayengi:
  saas_lottery/bootstrap_live_v4.php
  saas_lottery/config_live_v4.php
  draw-live-v4/index.php
  assets/css/shreewin-wheel500-hotfix.css

STEPS:
------
1. cPanel -> File Manager -> public_html.
2. Backup: saas_lottery, draw-live-v4, assets/css folders ka copy
   (ya kam se kam in 4 files ke .bak).
3. Zip upload -> public_html me Extract.
4. Phone par site khol ke HARD refresh (ya app kill karke dobara).
   Ctrl+F5 (browser) / app cache clear (WebView).

VERIFY TICKET:
--------------
[1] WinGo 1M: screen ka period number = upstream se 1 piche.        (v1)
[2] Timer 0 hote hi usi period ka result history ke top par.          (v2)
[3] (v3-naya) Timer chalraha hai: bet lagao -> My History me bet
    'ongoing/pending' dikhe, balance na kate-juade jaise settle ho.
    Timer end hote hi won/lost + balance update. Ek bhi bet hamesha
    ke liye stuck nahi hona chahiye (max ~2-3 sec baad settle).
[4] (v3-naya) 30sec game: number balls (0-9), Random/X1..X100 row aur
    tabs screen ke andar; left/right kuch kata hua nahi.
[5] K3 / 5D / TrxWinGo / MotoRace bhi khol ke dekh lo — sab par same
    lag + settlement gate laga hai.

ROLLBACK / CONTROL:
-------------------
- Sirf settlement ya lag off karna ho: config_live_v4.php me
  'period_lag' => 0. (CSS guard ko wapas laane ke liye .bak restore.)
- Poora rollback: in 4 files ko apne .bak se replace kar do. DB ka koi
  change nahi hai, data safe rahega.

GitHub raw links (individual files):
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/shreewin-update.zip
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/saas_lottery-bootstrap_live_v4.php
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/saas_lottery-config_live_v4.php
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/draw-live-v4-index.php
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/assets-css-shreewin-wheel500-hotfix.css

v4 CHANGE: Win/Lose popup (winning-loss popup) fix — GetWinLossResult ab
client ke timer-end par status null nahi dega: agar us period ka result DB me
cached hai to server pending bet ko usi request me settle karke seedha
won/lost + amount return karega. Popup ab clock-skew se unaffected aayega.

v5 CHANGE: WinLoss popup diagnostics — config_live_v4.php me naya key
'winloss_debug' => true (false karne par logging off). Har popup-query
ki entry log hoti hai: saas_lottery/logs/winloss.log (file khud ban jayegi).

v6 = v5 ka CRITICAL 500 fix: save-loop me undefined function call (purana
sl_settlement_allowed) fatale kar rahi thi -> results refresh-refresh ke baad
aa rahe the AUR GetWinLossResult 500 de raha tha (popup isliye nahi aaya).
Ab gate same batch wale; popup endpoint try/catch-protected (kabhi 500 nahi).
Sirf saas_lottery/bootstrap_live_v4.php replace karna hai.
