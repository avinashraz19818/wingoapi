Upload the folder contents to DhaniWin root, then run from SSH:
php repair.php
Then delete repair.php.
It tests MySQL, fixes balance column defaults, and syncs only users with game_balance=0 and wallet_balance>0. Make a DB backup first.
