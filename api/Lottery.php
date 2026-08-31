<?php
/**
 * Front controller for the Lottery API.
 *
 *   GET|POST /api/Lottery?action=...
 *
 * All responses use the envelope {data, code, msg, msgCode, serviceTime}.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\Api\Kernel;
use Lottery\App;

(new Kernel(App::boot()))->handle();
