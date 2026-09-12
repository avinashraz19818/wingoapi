Fixes Wingo getGameBalance returning zero when the web client sends its token in JSON or cookie.
It now reads token from Authorization, request fields, JSON body (token/accessToken/authorization), and ar_token/token cookies.
It still never falls back to another user's balance.
Upload api/_bootstrap.php and overwrite, then reload PHP-FPM and login again.
