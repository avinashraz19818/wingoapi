Final balance behavior:
- API no longer copies wallet into game on every refresh.
- Admin adjustment updates both wallet_balance and game_balance exactly once.
- Betting then deducts from game_balance normally and refresh will not restore it.
Upload api/_bootstrap.php and admin/controllers/UserController.php.
