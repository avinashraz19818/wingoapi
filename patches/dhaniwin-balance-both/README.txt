Admin balance adjustment sync fix.
Any admin adjustment now adds/subtracts the same amount from both wallet_balance and game_balance, so it appears in Wingo and Main Wallet.
Existing old wallet-only balance is not automatically copied. Run the optional SQL below once only if desired:
UPDATE api_users SET game_balance = wallet_balance;
Back up the database first. This makes each user's current game balance equal to their wallet balance.
