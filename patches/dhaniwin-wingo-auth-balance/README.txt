Final Wingo balance auth fix.
getargamebalance reads the logged-in user token from Authorization, request/body token, cookies, X-Token, X-Access-Token, Access-Token and Token headers.
Upload api/_bootstrap.php to DhaniWin/api and overwrite. This never falls back to another user's balance.
