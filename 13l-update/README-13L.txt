V30 — PER-GAME NATIVE VIEW LIFECYCLE + ORIGINAL TYPOGRAPHY
=======================================================
UI-only update. Server per-game filtering verified: 30S total11, 1M total19,
3M/5M total0 in the diagnostic account; no row from a different game.
- Native WinGo history KeepAlive receives a selected-game key. Switching game
  remounts ONLY history/Chart, clears old pagination/cache and prevents late
  responses from unmounted history views appearing in the newly selected game.
- Uses Vue's native row templates, API calls, authentication and detail actions.
  No all-game filler, token scan, bet/wallet changes or compiled-bundle edits.
- Original native/DhaniWin font sizes restored: period .37333rem, time .32rem,
  chart period .32rem, chip .96rem; original badge/row spacing. Palette inherited.
- Vue production builds without DOM __vueParentComponent use mounted VNode walk.
- Tests: KeepAlive+Suspense plain-render AND optimized compiled-template fixtures,
  delayed old response, four games including empty3M, page reset and betting
  component mounted only once. Legacy hide timers: 320/360/390/430px, >5s,
  ten visible chart rows, no hidden tested cells or horizontal clipping.
- Live acceptance after deployment requires phone key30/history30/chart30 PINGs.
  key30 confirms native history render hook. history30 reports selected game and
  wrong-prefix count plus font size. Do not call local fixtures live proof.

V29 LIVE-SMOKE FOLLOW-UP
=======================
Provider capped a 100-row request at ten, with current period among them.
Adapter now follows pageNo (bounded by 12 pages / 8s fetch budget), validates
and de-duplicates closed provider rows, caches a per-site/game provider-only
window and stops if upstream ignores pageNo. No DB/deterministic fallback.
First-page ten closed rows verified only after deployment; don't fabricate
current/unknown results to force a count. Source CSS unchanged from v28;
JS diagnostic markers bumped to boot29/history29/chart29.

V28 — TARGETED HISTORY/CHART PATCH (2026-09-08)
==============================================
Status: locally regression-tested; LIVE acceptance requires /13l-net.php PINGs.
- Compared actual DhaniWin and 13L MyRecord/Trend modules and backend contracts.
- Legacy limitHistory/forceGameHistoryPage10 hide nested cells and van-row nodes.
  Scoped native display rules beat those timers without rewriting any core JS.
- Removed v27 all-game/JWT filler and amount-based row matching entirely.
  Native per-game API rows, amounts, timestamps, detail toggles remain authoritative.
- WinGo Chart: provider-only closed history; 10 rows/page, actual totals.
  No synthesized fallback digit. Feed unavailable means no fabricated history.
- Trend data is now the native ten-digit array with missingCount, avgMissing,
  openCount, maxContinuous. Statistics use up to 100 available provider rows;
  sampleCount records the actual window (never pads missing provider rows).
- OverlayPing undefined $d corrected to $data; separate PING tail in net viewer.
- Wallet/settlement engine, DB configuration, header branding and site core files
  unchanged. No index.html in this ZIP. UI changes only hist25.js / patch25.css.
- Local tests: PHP syntax; period gate, provider row validation, per-game filter,
  zero result, real pagination/stats; Chromium 320/360/390/430, legacy timers
  running for 5.3 seconds, 10 visible chart rows, zero hidden tested cells.
- Deploy only through the owner's approved GitHub branch + 13l-deploy.php loop.
  After deployment read net viewer for boot28/history28/chart28. Do not claim
  phone acceptance from local fixture tests alone.

Historical release notes below (older instructions may be superseded):
-------------------------------------------------------------------

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

V11.2 — FEED BACKFIX KA GUPT BLOCKER HATA (bridge bind_param):
  lottery_bridge lb_sync me INSERT 8 placeholder + NOW() tha par sirf 7
  variables bind ho rahe the (open_time miss) → MySQL har feed row REJECT
  kar deta tha → feed backfill v6 se silently ZERO hi save kar raha tha!
  (Isiliye purane periods ki result rows missing thi aur repairs adhuri
  lagti thi.) Ab open_time issue-number se derive karke bind hota hai,
  types = ssssssii. Lint OK.
  User action: 13l-deploy.php ka link ek baar phir kholo — bas.

V11.3 — deploy me CDN cache-buster (?ts=) + doctor 'has_bridge_fix' marker.
  (Server state 07:30 IST: saari 9 bets official results se CONSISTENT,
   autorepair live, watermark fixed:0 = converged. Baaki sirf v11.2 bridge
   fix pending hai — GitHub CDN ~10 min baar fresh zip deta hai.)

V13 — MY HISTORY = PURI HISTORY (frontend issue ka asli fix):
  Skin ka MyGameRecord page server se hamesa SIRF current game ki bets mangta
  tha (gameCode filter) — user ne WinGo_30S + WinGo_1M dono kheli thi, isliye
  "sara history nahi dikh raha" tha. Server-side fix: handle_lottery_record ab
  gameCode filter IGNORE karta hai — user ki saari bets (sab games) ek list
  me, latest first. Win/Lose badges phle se hi sahi hain.
  Verify: API me totalCount = user ke saare bets (9), rows me 1M bhi.

V13.1 — HISTORY WIN AMOUNT EXACT:
  Skin "+jeeta" dikhata hai realAmount+fee+winLose se. realAmount ab
  stake-FEE (post-tax) jaata hai → +₹1,960 (jo sach me credit hua),
  pehle +₹1,980 (fee double-count) dikh raha tha. DB/wallet/popup untouched
  — sirf display. Verify: 52327 Big = +1,960 ; 52305 Big = +19,600.

V15 — SPY LOG: router ab har My-history/Game-history request ko
api/_core/.netlog me note karta hai (app ne kya maanga, server ne kitna
bheja) + 13l-net.php use tail + deploy/inject status dikhata hai. Isse
"rows gyab" ka final faisla hoga: app cache vs pageSize vs CSS clip.

V16 — APP CACHE KA ASLI KAAT: netlog ne prove kiya app history API call
hi nahi kar raha — sab kuch localStorage 'allGames' cache se render hota
tha (purana/pura data, 3 rows waghairah). 13l-jsinject.php skin ki entry
JS ke end me ek-one-clear patch lagata hai: login-token safe, sirf
history-cache keys + vuex blob ke nested cache fields remove, ek baar,
auto-reload → uske baad hamesha LIVE data. Rollback: .bak13l.

V17 — "1 SEC ME GAYAB" KA ASLI KAAT: skin ka runtime JS tab mount ke baad
list ke parent ko fixed height + overflow:hidden lock kar deta hai
(empty-state height 5.33rem ≈ 3 rows = '2 full + teesre ka sirf amount').
13l-unclip.php entry JS me watchdog patch karta hai: har 400ms + click/resize
par history containers ke parent chain ko un-clip karta rehta hai (sirf
history nodes; baaki UI untouched). Rollback: .bak13lu backup.

V18 — PER-GAME HISTORY (user ne clear kiya: har tab apni bets dikhaye —
  v13 ka all-games mix REVERTED, original filter wapas) + 13l-allfix.php:
  ek click me theme-CSS reveal rules + un-clip watchdog (marker-safe) +
  deploy/netlog report. Yehi final history behaviour hai.

V19 — FINAL CACHE-UNSTUCK: skin store hydration k random-named
localStorage keys se purani rows (v10 format: no betContent '_', fee=0)
ghisi jaati thi — isliye period/left column khaali. 13l-v19.php entry
bundle me content-based purge patch daalta hai (jo key bhi history-row
JSON rakhe → strip/remove; tokens safe) + stale row dikhe to pagination
poke karke live refetch. Marker '13L-V19-UNSTUCK', backup .bak19.

V20 SKINSYNC — user ne bola DhaniWin ka skin copy karo: 13l-skinsync.php
13L server se hi dhaniwin.club9.eu.cc ka poora js/css chunk graph BFS se
download karke local js/ css/ me daalta hai; hardcoded dhaniwin domains/
brand strings → 13L; index.html ka SIRF entry (js/index-*.js, css/
index-*.css) swap hota hai — title, favicon, /webapi config, custom fix
scripts (share-fix, native-*, v24-v26) sab untouched. Backup:
index.html.bakskin. Pehle &dry=1 se dry-run, phir real. Purane skin files
delete nahi hote (rollback = index.html.bakskin wapas copy).

V20.1 RESTORE — skinsync se site kharab hui to: 13l-restore.php =
index.html.bakskin se html wapas + overwrite hui entry js ko .bak13l se
restore + reference/missing asset report + css-marker check. Data/DB
untouched. Post-restore: cssinject + allfix links dobara chala sakte hain
(marker-safe idempotent).

V21 — VERIFY-FIRST: 13l-rows.php read-only dump: (A) last 12 bets — DB raw
vs EXACT API row (issueNumber>=17d? betContent has '_'? betTime epoch? premium
present?) (B) game-history/chart sample (C) patch markers on entry js + theme
css (D) netlog tail. Use: agent khud fetch karke verify karta hai, user ko
sirf deploy + (missing ho) 13l-v19 link.

V22 HISTFIX — FINAL RENDER GUARANTEE: 13l-histfix.php js/13l-overlay.js me
DOM-filler add karta hai: My-history ki khaali cells (period/time/chip) ko
LIVE /webapi/Lottery/GetMyGameRecord rows se bhar deta hai (numeric amount
match, svg-safe, data-13lf once-markers). Skin ke andar ka jo bhi stale
store ho, screen sahi dikhegi. Rollback: .bak22 truncate.

V24 BEACON-HISTFIX: filler ab phone se khud report karta hai —
Site/OverlayPing endpoint (router) netlog me 'PING' lines likhta hai:
boot/fetch-ok/filled=N/scan bad=N + pehli baad 400-char DOM snippet
(actual row HTML). Isse bina screenshot ke remote diagnosis possible.
Plus: index.html me overlay <script> tag check/add (css-link-only false
positive fixed). Marker 13L-HISTFIX-v24, .bak24 backups.

V25 CACHE-BREAK — root cause of ALL "patch not working": phone/webview
serving OLD cached js/css (overlay PING never fired despite file patched).
13l-histfix v25: fresh-named /js/13l-hist25.js (filler+beacon) + /css/
13l-patch25.css + index.html tags with ?cb= + sw.js kill (delete caches,
unregister, reload) + .htaccess no-cache for html/js/css + netlog auto-trim.
Backups: .bak25/.bak25b/.bak25c.

V26 — hist25.js rewrite: invisible-cell repair (DOM me text hai par height-0),
DOM-snippet beacon (scan26 bad=N → row HTML), header tweaks: 13L logo center +
'Deposit' button hide. Deploy auto-run karta hai. Same URL + no-cache = fresh.

V26b — headFix fix: logo sirf khud center (position:absolute+margin:auto trick),
header bar/back button UNTOUCHED — back button left pe apni jagah. Deposit-hide
same. Deploy auto-run.

V26c — CRITICAL: v26b ne game page pe poori header-row chhupa di (Deposit ka
parent row nikla). Ab: sirf 'Deposit'-matra-content element hide (width<=60vw,
no img children), logo center absolute trick with ancestor-safe guard.

V26d — logo fix: fit-content HATAAYA (usse natural 600px logo header me giant
dikh raha tha). Ab: size/site-ki-apni-CSS-jaisi, sirf left:50%+translate(-50%,
-50%) se center, max-width 62% guard, idempotent marker /*13lc*/.

V26E — headFix GATE: home page (pathname '/' ya '') pe headFix bilkul NAHI chalta
— logo center + Deposit-hide sirf game/history sub-pages pe. (Home pe logo
center hone se balance overlap ho raha tha — user ne pakda.)

V26F — flicker fix: home→WinGo route change pe logo ~900ms tak left me dikhta
tha (tick interval). Ab headFix 150ms fast-loop + pushState/replaceState/
popstate hooks se mount ke SAME frame pe center. Idempotent marker /*13lc*/ se
dobara-dobara style write nahi → no jitter.

V27 — (1) Chart panel unclip: 'Max consecutive' wale page pe 17-digit period
row se upar walk karke clipped container (scrollHeight>clientHeight) ko
height:auto+overflow:visible — ab 10/10 rows. (2) History flicker Khatam:
MutationObserver+rAF → Vue ke re-render ke AGLE frame me hi refill (900ms tick
sirf backstop). pings: chart27 unclip/noclip/no-row.
