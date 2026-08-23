<?php
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/api/ExternalLotteryAPI.php';

$api = new ExternalLotteryAPI();
$sample = "20260823100010430";
echo "Length: " . strlen($sample) . "\n";
// Format: 8 digits date (20260823) + 5 digits game type (10001) + 4 digits issue (0430)
