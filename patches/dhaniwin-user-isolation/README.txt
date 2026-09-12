SECURITY FIX: Wingo was falling back to the first database user when a UID/token was missing or invalid, causing another user's balance to appear.
Upload api/_bootstrap.php to dhaniwin/api/_bootstrap.php and overwrite. Clear PHP OPcache/reload PHP-FPM after upload.
Users must send their valid login token. Invalid/missing token now gets zero balance instead of another user's balance.
