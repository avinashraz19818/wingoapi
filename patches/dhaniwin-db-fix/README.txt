Important database fix: force MySQL instead of silently falling back to a separate SQLite database. This prevents admin and Wingo from reading different balances.
Upload api/config.php to dhaniwin/api/config.php and overwrite.
Ensure these are correct in cPanel: host localhost, database club532583_dhsuraj, username club532583_dhsuraj, password set privately.
After upload reload PHP-FPM and log in again.
