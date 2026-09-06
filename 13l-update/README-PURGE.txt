13L — EK BAAR KA PURGE FIX (results + colors match karne ke liye)
=========================================================
Status: 13l555 par patch LAG CHUKA hai (naya period number format isliye
show ho raha hai) — algorithm sahi chal raha hai. Problem sirf ye hai ki
DB me PURANE random results saved hain; engine DB row ko priority deta hai,
isliye wo purane periods kabhi match nahi karenge aur unke colors bhi
reference jaise nahi dikhenge.

FIX (30 second, ek baar):
1. Zip extract karo public_html me (sirf ek file jayegi: tools/13l-purge-results.php)
2. Browser me kholo:
   https://13l555.com/tools/13l-purge-results.php?key=13lpurge2026
3. JSON aayega: {"ok":true,"deleted":N,"left":0} = kaam khatam.
4. IMPORTANT: tools/13l-purge-results.php file ko DELETE kar do (security).

Iske baad:
- Har period ka result = same as reference site (crc32 deterministic)
- Balls/history colors = 0=red+violet, 5=green+violet, even=red, odd=green
  (reference site ke bilkul same — colors ka code dono engines me same hai)
- Purana data: lottery_bets / users / wallet — KUCH nahi badla, sirf
  lottery_results ki generated rows gayi hain (wo dobara ban jati hain)

Note: dono screenshots alag time ke the (ek 11:56 PM ka, ek 10:14 PM ka) —
isliye period 1106 vs 1005 dikhe; wo galat nahi tha. Ab same minute par
dono sites par same period + same number dikhega (purge ke baad).
