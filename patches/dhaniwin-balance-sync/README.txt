DhaniWin balance/config sync package.

Upload/overwrite these files:
- api/config.php
- api/_bootstrap.php
- _bootstrap.php
- admin/controllers/UserController.php

The API and admin use the same MySQL settings from api/config.php:
host localhost
database club532583_dhsuraj
username club532583_dhsuraj

Set DHANI_DB_PASS privately in cPanel/server environment. Do not publish the database password.
Admin must select Game Balance; the Wingo API reads api_users.game_balance.
