# Native v34 regression fixtures

User supplied IMG_20260908_154643_384.jpg and IMG_20260908_154649_763.jpg.
Clock displacement reproduced by running the old v33 headFix while scrolled.
Native source: https://13l.club9.eu.cc/js/dragon-65oA2ftS.js (6846 bytes).
Shipping tools/13l-native34.json contains exact replacement pairs plus before/
after SHA256. tests/native_patch_v34.py exercises the real PHP generator,
backup/atomic write/idempotence and unknown-build refusal; index stays unchanged.

native_round_v34.cjs executes the native composable patched by that manifest,
with real production Vue and a simulated provider clock/data service. It tests
zero rollover, delayed publication, rejected future rows, retry after errors,
old-game delayed responses, visibility resume, unmount cleanup and bet gating.
The native Worker is deliberately unavailable, exercising watchdog recovery.
No actual bets or transactions are submitted by these tests.

Optional LIVE_GAME=WinGo_30S or WinGo_1M connects this same harness to the site's
real PUBLIC issue/provider-history endpoints. Those integration results are
not logged-in phone/session evidence and do not verify actual wagers.
Clock screenshots use placeholder vector clocks, not replacement production art.
