<?php
/**
 * Apply pending schema migrations and reference data.
 *
 *   php tools/migrate.php            migrate + seed
 *   php tools/migrate.php --status   show current / latest version
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;
use Lottery\Database\Seeder;

$app      = App::boot();
$migrator = $app->migrator();

if (in_array('--status', $argv, true)) {
    fwrite(STDOUT, sprintf(
        "driver:  %s\ncurrent: %d\nlatest:  %d\n",
        $app->db()->driver(),
        $migrator->currentVersion(),
        $migrator->latestVersion()
    ));
    exit(0);
}

$applied = $migrator->migrate();
(new Seeder($app->db(), $app->config()))->run();

fwrite(STDOUT, $applied === []
    ? "Schema already up to date (version {$migrator->currentVersion()}); seeds refreshed.\n"
    : 'Applied migrations: ' . implode(', ', $applied) . "\n");
