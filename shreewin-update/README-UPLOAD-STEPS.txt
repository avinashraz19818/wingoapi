SHREEWIN - 1 PERIOD PICHE (RESULT LAG) UPDATE  v2
==================================================
v2 me fix: result TIMER END KARTE HI history me aayega (pehle server-clock
boundary se bind tha, ab provider ke apne latest-closed signal se bhi fire
hotа hai — dono me jo pehle). Reveal kabhi premature nahi hoga (safety clamp).

FILES AUR UNKE CPANEL TARGET PATH (3 files):
--------------------------------------------
1) saas_lottery/bootstrap_live_v4.php   -> replace   (engine + lag + reveal gate)
2) saas_lottery/config_live_v4.php      -> replace   (period_lag => 1)
3) draw-live-v4/index.php               -> replace   (boundary-wait window widen:
                                          timer ke aas-paas ki request hold ho
                                          kar result ready hote hi deti hai)

EXTRACT-READY ZIP:
------------------
shreewin-update.zip ke andar exactly wahi folder structure hai:
  saas_lottery/bootstrap_live_v4.php
  saas_lottery/config_live_v4.php
  draw-live-v4/index.php
Ise public_html me upload karo -> right-click -> Extract Now.
Files khud sahi folders me overwrite ho jayengi.
(cPanel Extract overwrite karta hai; isliye pehle backup zaroor le lena.)

STEPS:
------
1. cPanel -> File Manager -> public_html jao.
2. Backup: saas_lottery aur draw-live-v4 folders ka copy bana lo
   (ya kam se kam in 3 files ka .bak).
3. shreewin-update.zip upload karo -> public_html me hi Extract karo.
4. Bas. DB nahi chhedna, cron nahi, frontend/assets untouched hain isliye
   CSS/UI bilkul same rahega. Hard refresh (Ctrl+F5) karke test karo.

VERIFY KARNE KA TICKET:
-----------------------
- Game kholo (WinGo 1M): on-screen period number = upstream se 1 piche. ✓
- TIMER TEST (v2 ka main fix): jab countdown 0 ho, USI period ka result
  TURANT history/list me top row ke roop me dikhna chahiye
  (zyada se zyada ~2-5 sec, kyunki request boundary par hold hoti hai).
- Result animation ke baad agli period ka number continue kare (no skips).
- Trend page aur Records page bhi lagged history hi dikhayenge (consistent).
- Test bet: bet accept hoga, period close hote hi settle (balance update).

CONTROL:
--------
config_live_v4.php me:
  'period_lag' => 1  -> 1 period piche (current setting)
  'period_lag' => 0  -> wapas live (reveal behaviour bhi purana)
  'period_lag' => 2  -> 2 period piche (30s games ke liye option)

ROLLBACK:
---------
saas_lottery_bak / draw-live-v4 wapas copy kar lo, ya in 3 files ko .bak se
restore kar do. Koi DB change nahi hai to data safe rahega.

Direct download (GitHub raw links):
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/shreewin-update.zip
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/saas_lottery-bootstrap_live_v4.php
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/saas_lottery-config_live_v4.php
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/draw-live-v4-index.php
