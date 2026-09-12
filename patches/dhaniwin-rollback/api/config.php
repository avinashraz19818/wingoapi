<?php
return [
    'timezone' => getenv('DHANI_TIMEZONE') ?: 'Asia/Kolkata',
    'site' => [
        'name' => getenv('DHANI_SITE_NAME') ?: 'Dhani.win',
        'tenant_id' => (int) (getenv('DHANI_TENANT_ID') ?: 6006),
        'currency' => getenv('DHANI_CURRENCY') ?: 'INR',
    ],
    'db' => [
        'driver' => getenv('DHANI_DB_DRIVER') ?: 'auto',
        'sqlite_path' => getenv('DHANI_SQLITE_PATH') ?: __DIR__ . '/storage/dhaniwin.sqlite',
        'mysql' => [
            'host' => getenv('DHANI_DB_HOST') ?: 'localhost',
            'port' => getenv('DHANI_DB_PORT') ?: '3306',
            'database' => getenv('DHANI_DB_NAME') ?: 'club532583_cobra',
            'username' => getenv('DHANI_DB_USER') ?: 'club532583_cobra',
            'password' => getenv('DHANI_DB_PASS') ?: 'club532583_cobra',
        ],
    ],
    'admin' => [
        'username' => getenv('DHANI_ADMIN_USER') ?: 'admin',
        'password' => getenv('DHANI_ADMIN_PASS') ?: 'cobra123',
    ],
];

