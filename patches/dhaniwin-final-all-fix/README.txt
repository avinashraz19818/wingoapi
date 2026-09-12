FINAL DHANIWIN BALANCE PACKAGE
Upload/extract this ZIP in the DhaniWin root and overwrite files.
Files: api/config.php, api/_bootstrap.php, _bootstrap.php, admin/controllers/UserController.php, repair.php.
Then open https://YOUR-DOMAIN/repair.php once. It syncs users where game_balance=0 and wallet_balance>0.
After DONE, DELETE repair.php, logout/login, and test Wingo.
Do not use old balance ZIPs. Make a database backup first.
