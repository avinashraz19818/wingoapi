Fixes Wingo showing 0 while main wallet has balance.
On API user load, if wallet_balance is higher than game_balance, it syncs game_balance to wallet_balance and returns that value to Wingo.
Upload api/_bootstrap.php to dhaniwin/api/_bootstrap.php and overwrite.
