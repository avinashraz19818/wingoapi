DHANIWIN — LAG ENGINE FULL PACKAGE (extract & go)
===================================================
Ye wahi system hai jo ShreeWin par chal raha hai: 1-period-piche result,
timer-end reveal, pending tak settlement nahi, win/lose popup guaranteed,
My-history time bhi period ke saath piche, Follow Strategy removed, mobile
layout guard, wheel "Get ₹500" label + Add-to-Desktop ✕.

ZIP me kya hai (public_html ke SAME paths — direct Extract Now):
  saas_lottery/bootstrap_live_v4.php        ← poora engine (lag+reveal+settle+popup+time)
  saas_lottery/config_live_v4.php           ← period_lag=1 + debug switch
  draw-live-v4/index.php                    ← public draw feed (boundary-wait)
  api-live-v4/Lottery/index.php             ← game API gateway (Follow OFF)
  assets/css/dhaniwin-wheel500-hotfix.css   ← layout guard + wheel label style
  assets/js/dhaniwin-wheel500-hotfix.js     ← wheel label + Add-to-Desktop ✕
  web/config                                ← upar wali 2 files khud load karta hai
  README-DHANIWIN.txt                        ← ye file

PREREQUISITE (zaroori):
-----------------------
DhaniWin hosting usi project se bani honi chahiye jisse ShreeWin bani thi —
yaani public_html me pehle se maujood:
  developer-maruf/  (conn.php = APNI DB ki details, functions2.php, app_core...)
Agar ye folders hain to sab kaam karega. Agar nahi hain to pehle project ka
base deploy karo — ye zip sirf upar ke files daalta hai, DB/conn.php ko
CHHUTA BHI NAHI aur USE REPLACE BHI NAHI KARTA.

STEPS:
------
1. cPanel → File Manager → public_html.
2. (Optional backup) saas_lottery, draw-live-v4, api-live-v4, web, assets
   folders ka .bak bana lo.
3. dhaniwin-update.zip upload → right-click → Extract Now (public_html ke
   andar). "Overwrite" confirm karo.
4. App/site ek baar band karke kholo (browser me Ctrl+F5).
5. Pehli API request par saas_lottery ki tables khud ban jayengi
   (sl_install_schema automatic hai — DB user me CREATE permission chahiye).

VERIFY (2 minute):
------------------
[1] WinGo 1M kholo: screen ka period = upstream se 1 piche.
[2] Timer 0 par result history me; bet us period tak PENDING dikhega,
    timer ke 0 hote hi settle + Win/Lose popup turant.
[3] My History me period ke neeche ka time bhi 1 period piche.
[4] Follow Strategy tab gayab (Record/Trend/My history thik).
[5] Phone me balls/X1..X100 row cut na ho (layout guard).

CONTROL / KILL SWITCHES (saas_lottery/config_live_v4.php):
----------------------------------------------------------
  'period_lag' => 1        // 0 = sab live (lag off), 2 = 2 period piche
  'winloss_debug' => true  // false karo to saas_lottery/logs/winloss.log
                           // logging band (baad me folder delete bhi kar sakte ho)
Wheel label badalna ho: assets/js/dhaniwin-wheel500-hotfix.js me
  var rewardText = 'Get ₹500';  ← yahan text edit karo.
Follow Strategy wapis chahiye to: api-live-v4/Lottery/index.php me
  'isOpenFollow'=>false  →  true  kar do.

TROUBLE:
--------
- Site bilkul na khule (white screen): /web/config extract nahi hua hoga —
  manual option: index.html me </head> se pehle ye 2 line daal do:
    <link rel="stylesheet" href="/assets/css/dhaniwin-wheel500-hotfix.css?v=20260905-dhaniwin-1">
    <script src="/assets/js/dhaniwin-wheel500-hotfix.js?v=20260905-dhaniwin-1" defer></script>
  (aur web/config ko apni purani copy se restore kar do)
- Games me "Database connection unavailable": developer-maruf/conn.php me
  APNI DhaniWin DB ka user/pass/name daalo (ye zip is file ko change nahi
  karta — ShreeWin ki DB details wahan se copy MAT karna).
- Bets 500 de rahe ho: saas_lottery/logs/ banana allowed nahi (permission) —
  folder khud ban jayega jab writable hoga; nahi to bas winloss_debug false.
- Table permission error: DB user ko GRANT CREATE, ALTER, INDEX do ya panel
  se full rights wala user lagao.

(Sirf display logic lagged hai; DB me har cheez real time me save hoti hai —
audit/payout reports sahi rahenge.)
