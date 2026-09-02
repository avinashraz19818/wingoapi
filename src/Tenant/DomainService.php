<?php

declare(strict_types=1);

namespace Lottery\Tenant;

use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;
use Lottery\Support\Response;
use PDOException;

/**
 * Domain whitelist for the public result feed.
 *
 * Only registered domains may read results. A caller is identified by, in
 * order of precedence:
 *
 *   1. API key  — `X-Api-Key` header or `key` / `apiKey` query parameter
 *                 (server-to-server integrations)
 *   2. Origin / Referer host — browser calls from the customer's website
 *   3. our own host — the built-in results board and admin panel
 *
 * Anything else is rejected with 403 DOMAIN_NOT_ALLOWED, and the attempt is
 * counted so the operator can see who is knocking.
 */
class DomainService
{
    private Connection $db;
    private string $selfHost;

    public function __construct(Connection $db, string $selfHost = '')
    {
        $this->db       = $db;
        $this->selfHost = self::normalise($selfHost);
    }

    /* ===================================================================
     |  Normalisation helpers
     * ================================================================ */

    /** "https://WWW.Shop.com:8443/path" -> "shop.com" */
    public static function normalise(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '//')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }
        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];
        $value = preg_replace('/^www\./', '', $value) ?? $value;

        return trim($value, '. ');
    }

    /** Host of an Origin/Referer header. */
    public static function hostOf(string $header): string
    {
        return self::normalise($header);
    }

    /* ===================================================================
     |  CRUD
     * ================================================================ */

    /**
     * @param array<int,string> $games empty = every game
     */
    public function create(string $domain, string $label, array $games, string $note, ?string $expiresAt = null): array
    {
        $domain = self::normalise($domain);
        $this->assertDomain($domain);

        if ($this->findByDomain($domain) !== null) {
            throw ApiException::conflict("Domain {$domain} is already whitelisted");
        }

        $id = $this->db->insertGetId(
            'INSERT INTO ' . Tables::DOMAINS . ' (domain, label, api_key, status, games, note, expires_at, created_at)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?)',
            [
                $domain,
                $label !== '' ? $label : null,
                self::generateKey(),
                $games === [] ? null : implode(',', $games),
                $note !== '' ? $note : null,
                $expiresAt,
                Clock::dateTime(),
            ]
        );

        Log::info('feed domain whitelisted', ['domain' => $domain]);

        return $this->present($this->find($id) ?? []);
    }

    public function update(int $id, array $input): array
    {
        $row = $this->find($id);
        if ($row === null) {
            throw ApiException::notFound('Domain not found');
        }

        $domain = isset($input['domain']) ? self::normalise((string) $input['domain']) : (string) $row['domain'];
        $this->assertDomain($domain);

        $existing = $this->findByDomain($domain);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            throw ApiException::conflict("Domain {$domain} is already whitelisted");
        }

        $games = $input['games'] ?? null;
        if (is_string($games)) {
            $games = array_values(array_filter(array_map('trim', explode(',', $games))));
        }

        $validateUrl = array_key_exists('validateUrl', $input)
            ? trim((string) $input['validateUrl'])
            : (string) ($row['validate_url'] ?? '');

        if ($validateUrl !== '' && !filter_var($validateUrl, FILTER_VALIDATE_URL)) {
            throw ApiException::validation('validateUrl must be a full URL, e.g. https://your-site.com/api/User/GetUserInfo');
        }

        $this->db->execute(
            'UPDATE ' . Tables::DOMAINS . '
                SET domain = ?, label = ?, games = ?, note = ?, status = ?, rate_limit = ?, expires_at = ?,
                    player_secret = ?, validate_url = ?, validate_method = ?, validate_ttl = ?
              WHERE id = ?',
            [
                $domain,
                (string) ($input['label'] ?? $row['label'] ?? '') ?: null,
                is_array($games) ? ($games === [] ? null : implode(',', $games)) : $row['games'],
                (string) ($input['note'] ?? $row['note'] ?? '') ?: null,
                isset($input['status']) ? ((int) $input['status'] === 1 ? 1 : 0) : (int) $row['status'],
                isset($input['rateLimit']) ? max(0, (int) $input['rateLimit']) : (int) $row['rate_limit'],
                $input['expiresAt'] ?? $row['expires_at'],
                array_key_exists('playerSecret', $input)
                    ? (trim((string) $input['playerSecret']) ?: null)
                    : ($row['player_secret'] ?? null),
                $validateUrl ?: null,
                strtoupper((string) ($input['validateMethod'] ?? $row['validate_method'] ?? 'POST')) === 'GET' ? 'GET' : 'POST',
                max(30, (int) ($input['validateTtl'] ?? $row['validate_ttl'] ?? 300)),
                $id,
            ]
        );

        return $this->present($this->find($id) ?? []);
    }

    public function setStatus(int $id, int $status): array
    {
        if ($this->db->execute(
            'UPDATE ' . Tables::DOMAINS . ' SET status = ? WHERE id = ?',
            [$status === 1 ? 1 : 0, $id]
        ) === 0) {
            throw ApiException::notFound('Domain not found');
        }

        return $this->present($this->find($id) ?? []);
    }

    public function rotateKey(int $id): array
    {
        if ($this->db->execute(
            'UPDATE ' . Tables::DOMAINS . ' SET api_key = ? WHERE id = ?',
            [self::generateKey(), $id]
        ) === 0) {
            throw ApiException::notFound('Domain not found');
        }

        return $this->present($this->find($id) ?? []);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM ' . Tables::DOMAINS . ' WHERE id = ?', [$id]) > 0;
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM ' . Tables::DOMAINS . ' WHERE id = ?', [$id]);
    }

    public function findByDomain(string $domain): ?array
    {
        return $this->db->fetch('SELECT * FROM ' . Tables::DOMAINS . ' WHERE domain = ?', [self::normalise($domain)]);
    }

    public function findByKey(string $key): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $key)) {
            return null;
        }
        return $this->db->fetch('SELECT * FROM ' . Tables::DOMAINS . ' WHERE api_key = ?', [$key]);
    }

    /** @return array{list:array<int,array>,totalCount:int,pageNo:int,pageSize:int,totalPage:int} */
    public function paginate(string $search, int $pageNo, int $pageSize): array
    {
        $where  = '1 = 1';
        $params = [];
        if ($search !== '') {
            $where   .= ' AND (domain LIKE ? OR label LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Tables::DOMAINS . ' WHERE ' . $where, $params);
        $rows  = $this->db->fetchAll(
            'SELECT * FROM ' . Tables::DOMAINS . ' WHERE ' . $where . '
              ORDER BY id DESC LIMIT ' . $pageSize . ' OFFSET ' . (($pageNo - 1) * $pageSize),
            $params
        );

        return [
            'list'       => array_map([$this, 'present'], $rows),
            'pageNo'     => $pageNo,
            'pageSize'   => $pageSize,
            'totalCount' => $total,
            'totalPage'  => (int) ceil($total / max(1, $pageSize)),
        ];
    }

    /* ===================================================================
     |  Access control
     * ================================================================ */

    /**
     * Decide whether a feed request may proceed.
     *
     * @return array{allowed:bool,domain:?array,origin:string,via:string,reason:string}
     */
    public function authorise(array $server, array $input, string $gameCode = ''): array
    {
        $key = (string) ($server['HTTP_X_API_KEY'] ?? $input['key'] ?? $input['apiKey'] ?? '');
        $originHeader = (string) ($server['HTTP_ORIGIN'] ?? '');
        $refererHeader = (string) ($server['HTTP_REFERER'] ?? '');
        $origin = self::hostOf($originHeader !== '' ? $originHeader : $refererHeader);
        $host   = self::normalise((string) ($server['HTTP_HOST'] ?? ''));

        // Our own site (results board, admin panel) is always allowed.
        if ($origin === '' && $key === '') {
            $origin = $host;
        }
        if ($origin !== '' && ($origin === $host || ($this->selfHost !== '' && $origin === $this->selfHost))) {
            return ['allowed' => true, 'domain' => null, 'origin' => $origin, 'via' => 'self', 'reason' => ''];
        }

        $row = $key !== '' ? $this->findByKey($key) : $this->findByDomain($origin);
        $via = $key !== '' ? 'api-key' : 'origin';

        if ($row === null) {
            $this->recordUnknown($origin, $key);
            return [
                'allowed' => false, 'domain' => null, 'origin' => $origin, 'via' => $via,
                'reason'  => $origin === '' && $key === ''
                    ? 'No Origin/Referer header and no API key supplied'
                    : 'Domain not whitelisted: ' . ($origin !== '' ? $origin : 'via api key'),
            ];
        }

        if ((int) $row['status'] !== 1) {
            $this->recordHit((int) $row['id'], false);
            return ['allowed' => false, 'domain' => $row, 'origin' => $origin, 'via' => $via, 'reason' => 'Domain is disabled'];
        }

        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < Clock::now()) {
            $this->recordHit((int) $row['id'], false);
            return ['allowed' => false, 'domain' => $row, 'origin' => $origin, 'via' => $via, 'reason' => 'Subscription expired'];
        }

        // When the key is used from a browser, the Origin must still match.
        if ($key !== '' && $origin !== '' && $origin !== self::normalise((string) $row['domain'])) {
            $this->recordHit((int) $row['id'], false);
            return [
                'allowed' => false, 'domain' => $row, 'origin' => $origin, 'via' => $via,
                'reason'  => 'API key does not belong to ' . $origin,
            ];
        }

        if ($gameCode !== '' && !$this->gameAllowed($row, $gameCode)) {
            $this->recordHit((int) $row['id'], false);
            return ['allowed' => false, 'domain' => $row, 'origin' => $origin, 'via' => $via, 'reason' => "Game {$gameCode} is not part of this plan"];
        }

        $this->recordHit((int) $row['id'], true);

        return ['allowed' => true, 'domain' => $row, 'origin' => $origin, 'via' => $via, 'reason' => ''];
    }

    public function gameAllowed(array $row, string $gameCode): bool
    {
        $games = trim((string) ($row['games'] ?? ''));
        if ($games === '') {
            return true;
        }

        $allowed = array_map('strtolower', array_map('trim', explode(',', $games)));

        return in_array(strtolower($gameCode), $allowed, true);
    }

    /** Throw the standard rejection for a failed {@see authorise()}. */
    public static function reject(string $reason): ApiException
    {
        return new ApiException(
            $reason !== '' ? $reason : 'This domain is not allowed to read the feed',
            Response::ERR_AUTH,
            'DOMAIN_NOT_ALLOWED',
            403
        );
    }

    /* ===================================================================
     |  Usage counters
     * ================================================================ */

    public function recordHit(int $domainId, bool $allowed): void
    {
        $day = date('Y-m-d', Clock::now());

        try {
            if ($allowed) {
                $this->db->execute(
                    'UPDATE ' . Tables::DOMAINS . ' SET requests_total = requests_total + 1, last_seen_at = ? WHERE id = ?',
                    [Clock::dateTime(), $domainId]
                );
            } else {
                $this->db->execute(
                    'UPDATE ' . Tables::DOMAINS . ' SET blocked_total = blocked_total + 1 WHERE id = ?',
                    [$domainId]
                );
            }

            $this->db->execute(
                $this->db->insertIgnore() . ' ' . Tables::DOMAIN_USAGE . ' (domain_id, day, requests, blocked) VALUES (?, ?, 0, 0)',
                [$domainId, $day]
            );
            $this->db->execute(
                'UPDATE ' . Tables::DOMAIN_USAGE . '
                    SET requests = requests + ?, blocked = blocked + ?
                  WHERE domain_id = ? AND day = ?',
                [$allowed ? 1 : 0, $allowed ? 0 : 1, $domainId, $day]
            );
        } catch (PDOException $e) {
            // Metrics must never break the feed.
        }
    }

    private function recordUnknown(string $origin, string $key): void
    {
        Log::warning('feed request from an unlisted source', [
            'origin' => $origin !== '' ? $origin : null,
            'key'    => $key !== '' ? substr($key, 0, 6) . '…' : null,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function usage(int $domainId, int $days = 14): array
    {
        return $this->db->fetchAll(
            'SELECT day, requests, blocked FROM ' . Tables::DOMAIN_USAGE . '
              WHERE domain_id = ? ORDER BY day DESC LIMIT ' . max(1, $days),
            [$domainId]
        );
    }

    /* ===================================================================
     |  Helpers
     * ================================================================ */

    public function present(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'id'          => (int) $row['id'],
            'domain'      => $row['domain'],
            'label'       => $row['label'],
            'apiKey'      => $row['api_key'],
            'status'      => (int) $row['status'],
            'games'       => $row['games'] === null || $row['games'] === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', (string) $row['games'])))),
            'rateLimit'   => (int) $row['rate_limit'],
            'requests'    => (int) $row['requests_total'],
            'blocked'     => (int) $row['blocked_total'],
            'note'          => $row['note'],
            'validateUrl'    => $row['validate_url'] ?? null,
            'validateMethod' => $row['validate_method'] ?? 'POST',
            'validateTtl'    => (int) ($row['validate_ttl'] ?? 300),
            'hasPlayerSecret'=> !empty($row['player_secret']),
            'expiresAt'   => $row['expires_at'],
            'lastSeenAt'  => $row['last_seen_at'],
            'createdAt'   => $row['created_at'],
        ];
    }

    private function assertDomain(string $domain): void
    {
        if ($domain === '' || !preg_match('/^(\*\.)?[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $domain)) {
            throw ApiException::validation('Enter a valid domain, e.g. client-site.com');
        }
    }

    public static function generateKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
