<?php

declare(strict_types=1);

namespace Lottery\Admin;

use Lottery\Database\Connection;
use Lottery\Database\Tables;
use Lottery\Games\Families\RulesFactory;
use Lottery\Games\GameDefinition;
use Lottery\Games\IssueNumber;
use Lottery\Support\ApiException;
use Lottery\Support\Clock;
use Lottery\Support\Log;

/**
 * Per-game / per-issue admin result overrides.
 *
 * Two modes:
 *   oneshot  targets one exact issue number; consumed and cleared after the draw
 *   legacy   the "next round" override used by older admin panels — stored
 *            against the placeholder issue 00000000000000000 and applied to
 *            whichever issue is drawn next, then cleared
 *
 * Overrides always beat the provider and the local generator.
 */
class OverrideService
{
    public const LEGACY_ISSUE = '00000000000000000';

    private Connection $db;
    private RulesFactory $rules;

    public function __construct(Connection $db, RulesFactory $rules)
    {
        $this->db    = $db;
        $this->rules = $rules;
    }

    /**
     * Queue an override. Passing the legacy placeholder (or an empty issue)
     * registers a one-shot "next issue" override.
     */
    public function set(
        GameDefinition $game,
        string $issueNumber,
        string $value,
        string $mode = 'oneshot',
        ?string $createdBy = null,
        ?string $note = null
    ): array {
        $mode = in_array($mode, ['oneshot', 'legacy'], true) ? $mode : 'oneshot';

        if ($issueNumber === '' || $issueNumber === self::LEGACY_ISSUE) {
            $issueNumber = self::LEGACY_ISSUE;
            $mode        = 'legacy';
        } else {
            IssueNumber::parse($issueNumber);
            if (!IssueNumber::belongsTo($issueNumber, $game)) {
                throw ApiException::validation('Issue number does not belong to ' . $game->code);
            }
        }

        // Validates the value against the family rules before it is stored.
        $this->rules->forGame($game)->fromOverride($value);

        $existing = $this->db->fetch(
            'SELECT id FROM ' . Tables::OVERRIDES . ' WHERE game_code = ? AND issue_number = ?',
            [$game->code, $issueNumber]
        );

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE ' . Tables::OVERRIDES . '
                    SET override_value = ?, mode = ?, status = ?, created_by = ?, note = ?, used_at = NULL, created_at = ?
                  WHERE id = ?',
                [$value, $mode, 'pending', $createdBy, $note, Clock::dateTime(), $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            $id = $this->db->insertGetId(
                'INSERT INTO ' . Tables::OVERRIDES . '
                    (game_code, issue_number, override_value, mode, status, created_by, note, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$game->code, $issueNumber, $value, $mode, 'pending', $createdBy, $note, Clock::dateTime()]
            );
        }

        Log::info('result override queued', [
            'game' => $game->code, 'issue' => $issueNumber, 'value' => $value, 'mode' => $mode,
        ]);

        return [
            'id'          => $id,
            'gameCode'    => $game->code,
            'issueNumber' => $issueNumber,
            'value'       => $value,
            'mode'        => $mode,
            'status'      => 'pending',
        ];
    }

    /**
     * Pending override applicable to this issue (exact match first, then the
     * legacy "next issue" row).
     */
    public function pendingFor(GameDefinition $game, string $issueNumber): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM ' . Tables::OVERRIDES . '
              WHERE game_code = ? AND issue_number = ? AND status = ?',
            [$game->code, $issueNumber, 'pending']
        );

        if ($row !== null) {
            return $row;
        }

        return $this->db->fetch(
            'SELECT * FROM ' . Tables::OVERRIDES . '
              WHERE game_code = ? AND issue_number = ? AND status = ?
              ORDER BY id ASC',
            [$game->code, self::LEGACY_ISSUE, 'pending']
        );
    }

    /** Mark an override as consumed; legacy rows are deleted outright. */
    public function consume(array $override, string $issueNumber): void
    {
        if (($override['mode'] ?? 'oneshot') === 'legacy') {
            $this->db->execute('DELETE FROM ' . Tables::OVERRIDES . ' WHERE id = ?', [$override['id']]);
        } else {
            $this->db->execute(
                'UPDATE ' . Tables::OVERRIDES . ' SET status = ?, used_at = ? WHERE id = ?',
                ['used', Clock::dateTime(), $override['id']]
            );
        }

        Log::info('result override applied', [
            'game'  => $override['game_code'],
            'issue' => $issueNumber,
            'value' => $override['override_value'],
            'mode'  => $override['mode'] ?? 'oneshot',
        ]);
    }

    public function cancel(GameDefinition $game, string $issueNumber): bool
    {
        $issueNumber = $issueNumber === '' ? self::LEGACY_ISSUE : $issueNumber;

        return $this->db->execute(
            'DELETE FROM ' . Tables::OVERRIDES . ' WHERE game_code = ? AND issue_number = ? AND status = ?',
            [$game->code, $issueNumber, 'pending']
        ) > 0;
    }

    /** @return array<int,array<string,mixed>> */
    public function listPending(?string $gameCode = null): array
    {
        if ($gameCode === null) {
            return $this->db->fetchAll(
                'SELECT * FROM ' . Tables::OVERRIDES . ' WHERE status = ? ORDER BY id DESC LIMIT 200',
                ['pending']
            );
        }

        return $this->db->fetchAll(
            'SELECT * FROM ' . Tables::OVERRIDES . ' WHERE status = ? AND game_code = ? ORDER BY id DESC LIMIT 200',
            ['pending', $gameCode]
        );
    }
}
