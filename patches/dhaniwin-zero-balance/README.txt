New registration default game balance is changed from 4.45/4.75 to 0.0.
Existing users are not changed by these files. To reset existing balances, back up DB first and run:
UPDATE api_users SET game_balance = 0 WHERE game_balance IN (4.45, 4.75);
