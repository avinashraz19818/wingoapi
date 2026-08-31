<?php
/**
 * Front controller for the public result feed.
 *
 *   GET /api/Feed?action=GameList
 *   GET /api/Feed?action=History&gameCode=WinGo_1M&pageSize=10
 *
 * Only whitelisted domains (Origin/Referer) or a valid X-Api-Key may read it.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\Api\FeedKernel;
use Lottery\App;

(new FeedKernel(App::boot()))->handle();
