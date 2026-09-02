<?php
/**
 * Regenerate schema.sql (MySQL 8+ reference DDL) from the migrations.
 *
 *   php tools/dump_schema.php > schema.sql
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;

echo App::boot()->migrator()->schemaSql(true);
