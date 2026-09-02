<?php
/**
 * Manage feed/partner domains from the command line — no MySQL password needed,
 * it reuses the app's own .env credentials.
 *
 *   php tools/domain.php list
 *   php tools/domain.php add   client-site.com "Client name"
 *   php tools/domain.php check client-site.com https://client-site.com/api/User/GetUserInfo [POST|GET]
 *   php tools/domain.php test  client-site.com <a real player token from that site>
 *   php tools/domain.php key   client-site.com          (show / rotate with --rotate)
 *   php tools/domain.php off   client-site.com
 *   php tools/domain.php on    client-site.com
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Lottery\App;
use Lottery\Tenant\DomainService;

$app     = App::boot();
$app->bootstrapDatabase();
$domains = $app->domains();

$cmd  = strtolower((string) ($argv[1] ?? 'list'));
$name = (string) ($argv[2] ?? '');

$out = static fn(string $line = '') => fwrite(STDOUT, $line . PHP_EOL);

$find = static function (string $name) use ($domains, $out): array {
    $row = $domains->findByDomain($name);
    if ($row === null) {
        $out("Domain not found: {$name}");
        exit(1);
    }
    return $row;
};

switch ($cmd) {
    case 'list':
        $page = $domains->paginate('', 1, 100);
        $out(sprintf('%-32s %-8s %-34s %s', 'DOMAIN', 'STATUS', 'TOKEN CHECK URL', 'API KEY'));
        foreach ($page['list'] as $d) {
            $out(sprintf(
                '%-32s %-8s %-34s %s',
                $d['domain'],
                $d['status'] === 1 ? 'allowed' : 'blocked',
                $d['validateUrl'] ?: ($d['hasPlayerSecret'] ? '(their JWT secret)' : '-'),
                $d['apiKey']
            ));
        }
        break;

    case 'add':
        $row = $domains->create($name, (string) ($argv[3] ?? ''), [], '');
        $out("Added {$row['domain']}");
        $out("API key: {$row['apiKey']}");
        break;

    case 'check':
        $row = $find($name);
        $url = (string) ($argv[3] ?? '');
        if ($url === '') {
            $out('Usage: php tools/domain.php check <domain> <token check url> [POST|GET]');
            exit(1);
        }
        $domains->update((int) $row['id'], [
            'validateUrl'    => $url,
            'validateMethod' => strtoupper((string) ($argv[4] ?? 'POST')),
        ]);
        $out("Token check URL set for {$name}:");
        $out('  ' . $url . ' (' . strtoupper((string) ($argv[4] ?? 'POST')) . ')');
        $out('Players signed in on that site can now use the lottery directly.');
        break;

    case 'test':
        $row   = $find($name);
        $token = (string) ($argv[3] ?? '');
        if ($token === '') {
            $out('Usage: php tools/domain.php test <domain> <a real token from that site>');
            exit(1);
        }
        if (empty($row['validate_url'])) {
            $out("No token check URL configured for {$name} — run the 'check' command first.");
            exit(1);
        }
        $out('Asking ' . $row['validate_url'] . ' who owns this token…');
        $user = $app->partners()->resolveIntrospectedToken($token, DomainService::normalise($name));
        if ($user === null) {
            $out('FAILED — the site did not return a usable user id.');
            $out('Check data/app.log, and confirm the URL/method are right.');
            exit(2);
        }
        $out('OK — token belongs to local player #' . $user['id'] . ' (' . $user['mobile'] . ')');
        $out('Balance: ' . $app->wallet()->snapshot($user['id'])['balance']);
        break;

    case 'key':
        $row = $find($name);
        if (in_array('--rotate', $argv, true)) {
            $row = $domains->rotateKey((int) $row['id']);
            $out('New API key: ' . $row['apiKey']);
        } else {
            $out('API key: ' . $row['api_key']);
        }
        break;

    case 'on':
    case 'off':
        $row = $find($name);
        $domains->setStatus((int) $row['id'], $cmd === 'on' ? 1 : 0);
        $out($name . ' is now ' . ($cmd === 'on' ? 'allowed' : 'blocked'));
        break;

    default:
        $out('Commands: list | add | check | test | key | on | off');
        exit(1);
}
