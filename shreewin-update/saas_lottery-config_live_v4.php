<?php
return array(
    // Public draw data only. Never place a captured bearer token in this file.
    'draw_base_url' => 'https://draw.ar-lottery01.com',
    // AR014/lottery7 uses this public feed for WinGo, K3, 5D and the other
    // SaaS games. Keep it authoritative so periods and results cannot drift
    // to the local clock/random generator.
    'force_remote_draw' => true,
    'history_page_size' => 10,
    'history_total_pages' => 50,
    'request_timeout_seconds' => 5,
    'bet_lock_seconds' => 5,
    // Display the period and results one full interval behind the upstream
    // feed (same 1-period buffer as the wingoapi engine). 1 = one period
    // behind for every game (30s game -> 30s buffer, 10m game -> 10m buffer).
    // Set to 0 to return to the live upstream timing.
    'period_lag' => 1,
    // Temporary diagnostics for the win/lose popup: one line per popup query
    // in saas_lottery/logs/winloss.log. Set to false to stop logging.
    'winloss_debug' => true,
    'maximum_stake' => 1000000,
    'games' => array(
        'WinGo_30S' => array('name' => 'WinGo 30sec', 'lottery' => 'WinGo', 'interval' => 0.5, 'sort' => 44),
        'WinGo_1M' => array('name' => 'WinGo 1 Min', 'lottery' => 'WinGo', 'interval' => 1, 'sort' => 43),
        'WinGo_3M' => array('name' => 'WinGo 3 Min', 'lottery' => 'WinGo', 'interval' => 3, 'sort' => 42),
        'WinGo_5M' => array('name' => 'WinGo 5 Min', 'lottery' => 'WinGo', 'interval' => 5, 'sort' => 41),

        'TrxWinGo_1M' => array('name' => 'TrxWinGo 1 Min', 'lottery' => 'TrxWinGo', 'interval' => 1, 'sort' => 14),
        'TrxWinGo_3M' => array('name' => 'TrxWinGo 3 Min', 'lottery' => 'TrxWinGo', 'interval' => 3, 'sort' => 13),
        'TrxWinGo_5M' => array('name' => 'TrxWinGo 5 Min', 'lottery' => 'TrxWinGo', 'interval' => 5, 'sort' => 12),
        'TrxWinGo_10M' => array('name' => 'TrxWinGo 10 Min', 'lottery' => 'TrxWinGo', 'interval' => 10, 'sort' => 11),

        'K3_1M' => array('name' => 'K3 1 Min', 'lottery' => 'K3', 'interval' => 1, 'sort' => 34),
        'K3_3M' => array('name' => 'K3 3 Min', 'lottery' => 'K3', 'interval' => 3, 'sort' => 33),
        'K3_5M' => array('name' => 'K3 5 Min', 'lottery' => 'K3', 'interval' => 5, 'sort' => 32),
        'K3_10M' => array('name' => 'K3 10 Min', 'lottery' => 'K3', 'interval' => 10, 'sort' => 31),

        'D5_1M' => array('name' => '5D 1 Min', 'lottery' => 'D5', 'interval' => 1, 'sort' => 24),
        'D5_3M' => array('name' => '5D 3 Min', 'lottery' => 'D5', 'interval' => 3, 'sort' => 23),
        'D5_5M' => array('name' => '5D 5 Min', 'lottery' => 'D5', 'interval' => 5, 'sort' => 22),
        'D5_10M' => array('name' => '5D 10 Min', 'lottery' => 'D5', 'interval' => 10, 'sort' => 21),

        'MotoRace_1M' => array('name' => 'Moto Racing', 'lottery' => 'MotoRace', 'interval' => 1, 'sort' => 0)
    )
);
