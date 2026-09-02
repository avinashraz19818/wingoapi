SHREEWIN - 1 PERIOD PICHE (RESULT LAG) UPDATE
==============================================

Ye folder me 2 updated PHP files hain jo ShreeWin ke liye wingoapi jaisa
"1 period piche" buffer laga deti hain (period number + result reveal
upstream se 1 poora period late dikhte hain; countdown real-time rehta hai).

FILES AUR UNKE CPANEL TARGET PATH:
---------------------------------
1) bootstrap_live_v4.php
   -> upload at: public_html/saas_lottery/bootstrap_live_v4.php   (replace)

2) config_live_v4.php
   -> upload at: public_html/saas_lottery/config_live_v4.php       (replace)
   -> isi me nayi line added hai: 'period_lag' => 1
      (0 = wapas live mode, 1 = 1 period piche, 2 = 2 period piche)

STEPS:
------
1. cPanel -> File Manager -> public_html -> saas_lottery folder kholo.
2. Purani dono files ka backup lo (right-click -> Copy, ya rename to .bak).
   NOTE: agar server wali config_live_v4.php me draw_base_url ya games list
   alag hai to mujhe bhej do, main sirf period_lag line add kar dunga.
3. Is folder ki dono files upload karo -> Overwrite/Replace karo.
4. Bas. DB change nahi, cron change nahi, frontend rebuild nahi.
   Site refresh hote hi naya period system chalega.

VERIFY:
-------
- WinGo 1M kholo: aapke site ka current period number upstream se 1 number
  piche hona chahiye.
- Result history me sabse latest row tab aayega jab aapka dikhaya hua period
  close hoga (upstream usse 1 period pehle reveal kar chuka hota hai).
- Test bet settle hona chahiye cycle ke end tak.

ZIP (ek saath download ke liye):
--------------------------------
shreewin-update.zip me same dono files hain.

GitHub direct download links (browser me khol ke Ctrl+S se save karo):
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/bootstrap_live_v4.php
  https://github.com/avinashraz19818/wingoapi/raw/arena/01a06254-wingoapi/shreewin-update/config_live_v4.php

EXTRACT-READY ZIP (recommended):
  shreewin-update.zip ke andar saas_lottery/ folder hai.
  Ise public_html me upload karo -> right-click -> Extract Now.
  Dono files khud saas_lottery/ me jaake overwrite ho jayengi.
  (cPanel Extract overwrite karta hai; isliye pehle backup zaroor le lena.)
