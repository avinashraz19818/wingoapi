<?php
/**
 * Front controller for existing "AR style" front-ends.
 *
 *   GET|POST /api/Compat?action=GetBalance&gameCode=WinGo_30S
 *
 * Answers in the client's own dialect while this platform provides the
 * results, the wallet and the settlement. See docs/COMPAT.md.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\Api\CompatKernel;
use Lottery\App;

(new CompatKernel(App::boot()))->handle();
