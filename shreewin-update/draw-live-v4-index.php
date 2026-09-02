<?php
/**
 * Uncached authoritative SaaS draw adapter.
 *
 * Public animation polling uses the same result cache as authenticated APIs.
 * The shared history handler repairs missing cache tables automatically and
 * falls back to the real provider feed if storage is temporarily unavailable.
 */
require_once dirname(__DIR__) . '/saas_lottery/bootstrap_live_v4.php';

/**
 * Near a timer boundary the browser may ask for history a few milliseconds
 * before the provider publishes the closing result. Hold that one request for
 * a short bounded window so its response already contains the period that just
 * ended; normal history requests still return immediately.
 */
function dlv4_history_with_boundary_wait($gameCode, $input)
{
    $expectedIssue = '';
    $pageNo = max(1, (int)($input['pageNo'] ?? 1));
    if ($pageNo === 1) {
        $current = sl_provider_current($gameCode);
        if (is_array($current) && isset($current['current']['issueNumber'], $current['current']['startTime'], $current['current']['endTime'])) {
            $now = sl_now_ms();
            $start = (int)$current['current']['startTime'];
            $end = (int)$current['current']['endTime'];
            $remaining = $end - $now;
            $age = $now - $start;
            if ($remaining >= -2000 && $remaining <= 6000) {
                $expectedIssue = (string)$current['current']['issueNumber'];
            } elseif ($age >= 0 && $age <= 8000 && isset($current['previous']['issueNumber'])) {
                $expectedIssue = (string)$current['previous']['issueNumber'];
            }
        }
    }

    $deadline = microtime(true) + 7.5;
    $data = array('list'=>array(),'pageNo'=>$pageNo,'totalPage'=>0,'totalCount'=>0);
    do {
        $data = sl_history_page($gameCode, $input);
        $data['list'] = sl_rebind_wingo_history_periods($gameCode, $data['list'] ?? array());
        $latestIssue = isset($data['list'][0]['issueNumber']) ? (string)$data['list'][0]['issueNumber'] : '';
        if ($expectedIssue === '' || ($latestIssue !== '' && strcmp($latestIssue, $expectedIssue) >= 0)) {
            break;
        }
        usleep(350000);
    } while (microtime(true) < $deadline);

    return $data;
}

try {
    $gameCode = isset($_GET['gameCode']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['gameCode']) : '';
    $lottery = isset($_GET['lottery']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['lottery']) : '';
    $game = sl_game_config($gameCode);
    if (!$game || (string) $game['lottery'] !== $lottery) {
        sl_fail(7, 'Unsupported game code', 7, 404);
    }

    if (!empty($_GET['history'])) {
        $input = $_GET;
        $input['pageNo'] = isset($_GET['pageNo']) ? max(1, (int) $_GET['pageNo']) : 1;
        $input['pageSize'] = 10;
        // Preserve the exact-period admin overlay and settlement. The shared
        // handler also self-installs missing history tables before the read.
        $data = dlv4_history_with_boundary_wait($gameCode, $input);
        $data['totalPage'] = 50;
        $data['totalCount'] = 500;
        sl_send(array('code' => 0, 'msg' => 'success', 'data' => $data), 200);
    }

    $payload = sl_provider_current($gameCode);
    if (!$payload) {
        sl_fail(503, 'Real current draw is temporarily unavailable', 503, 503);
    }
    sl_send($payload, 200);
} catch (Throwable $e) {
    error_log('[draw-live-v4] ' . $e->getMessage());
    sl_fail(500, 'Real draw service is temporarily unavailable', 500, 500);
}
