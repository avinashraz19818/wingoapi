<?php
/**
 * Front controller for the admin panel API.
 *
 *   GET|POST /api/Admin?action=...
 *
 * Auth: POST action=Login returns a session token, then send it as
 * `Authorization: Bearer <token>`. Machine clients may use `X-Admin-Token`.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\Api\AdminKernel;
use Lottery\App;

(new AdminKernel(App::boot()))->handle();
