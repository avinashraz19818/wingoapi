DHANIWIN — LAG ENGINE PATCH PACKAGE
=====================================
(2026-09-06 — dhaniwin repo ke actual structure ke hisaab se banaya gaya,
public_html/.htaccess + api/_bootstrap.php wale engine ke liye.)

ZIP me kya hai (4 files + ye README) — sab public_html ke SAME path par:
  api/_bootstrap.php            ← engine patch (neeche dekho)
  index.html                    ← 2 tag add hue (overlay loader)
  css/dhaniwin-overlay.css      ← layout guard + ✕ styling
  js/dhaniwin-overlay.js        ← Add-to-Desktop pill me ✕ button
  README-DHANIWIN.txt

YE PEHLE SE HI DHANIWIN CODEBASE ME HAI (isliye dobara nahi banaya):
  ✓ Current period number 1 period piche (api_lottery_issue_data ka lag)
  ✓ Result/history timer-end par reveal (history hamesha latest CLOSED round
    se shuru hoti hai — beech ka period kabhi nahi dikhta)
  ✓ Bet tab tak pending jab tak us period ka timer khatam (issue_closed gate)
  ✓ Multiplier row X1 X5 X10 X20 X50 X100 (betMultiples pehle se sahi)

IS PATCH ME KYA NAYA HAI:
  1) WIN/LOSE POPUP RACE-FIX — client timer 0 hote hi GetWinLossResult EK baar
     poochta hai; agar request server ke boundary flip se 1-6 second pehle
     pahunch jaye (phone ki ghadi thodi aage), to pehle "pending" jawab milta
     aur popup hamesha ke liye chala jata. Ab server us case me settlement ka
     PREVIEW (exactly wahi deterministic result jo real settlement use karega)
     bhej deta hai — popup guaranteed. DB me tab tak kuch settle nahi hota,
     to history/wallet true boundary par hi update honge (koi leak nahi).
  2) MY-HISTORY TIME BHI PICHE — period ke neeche jo bet ka timestamp dikhta
     tha wo live clock ka tha (bet 16:08:30 par -> period 16:07 wala). Ab
     betTime/createTime/createdTime teeno 1 period (game ke interval jitna:
     30s game -30s, 1M -60s, 5M -5min) piche dikhte hain. DB me real time.
  3) FOLLOW STRATEGY OFF — GetUserInfo ab isOpenFollow:false bhejta hai; game
     screen ka Follow Strategy tab band. Wapis chahiye to api/_bootstrap.php
     line ~1771 'isOpenFollow' => true.
  4) MOBILE LAYOUT GUARD — rem base lock (screen/10, desktop 40px cap),
     bet panel ke balls/multiplier/tabs edge se nahi katenge.
  5) ADD-TO-DESKTOP PILL me ✕ close button (session ke liye hide).

STEPS:
------
1. cPanel → File Manager → public_html.
2. Backup: api/_bootstrap.php aur index.html ke .bak bana lo.
3. Zip upload → public_html me Extract → overwrite confirm.
4. Site ek baar refresh (js/css par already no-cache headers lagi hain,
   isliye hard-refresh ki majboori nahi; kar lo to safe).

NOTE — upstream bridge ke baare me:
  Admin panel me agar 'lottery_upstream_url' setting lagi hai to lottery ke
  endpoints external engine se aate hain aur ye patch ka win-loss/records
  local path bypass ho sakta hai. Default nahi lagi — check:
  Admin → Settings me lottery_upstream_url khali hona chahiye.

DB/credentials: is package se config.php ya conn ki koi file touch nahi hoti.
Agar naye hosting par DB alag hai to api/config.php (env fallbacks) me apni
values rakhna — club532583_cobra defaults sirf repo me likhe hain.

VERIFY (2 min):
---------------
[1] WinGo 1M: period number live se 1 piche, timer theek chal raha.
[2] Bet lagao → My History me pending → timer 0 par: result + popup turant.
[3] My History row: period ke neeche time us period ki window me (e.g. period
    ...1607 → time 16:07:xx), 16:08:xx NAHI.
[4] Follow Strategy tab gayab; Trend/Record/My history theek.
[5] Phone par bet panel: balls/X-chips cut nahi; pill me ✕ → tap → hide.

ROLLBACK: index.html.bak aur api/_bootstrap.php.bak ko restore karo, aur
css/dhaniwin-overlay.css + js/dhaniwin-overlay.js delete kar do.
