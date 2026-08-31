<?php

declare(strict_types=1);

namespace Lottery\Games;

use Lottery\Support\ApiException;

/**
 * Builds the catalogue of games from config and answers lookups by code.
 *
 * Family codes are the 3-digit segment of the 17-digit issue number and must
 * never change once a game has gone live.
 */
class GameRegistry
{
    public const FAMILY_CODES = [
        'WinGo'    => '100',
        'TrxWinGo' => '200',
        'K3'       => '300',
        'D5'       => '400',
        'MotoRace' => '500',
    ];

    /** Client-friendly aliases accepted on input. */
    public const FAMILY_ALIASES = [
        '5d'        => 'D5',
        'd5'        => 'D5',
        'wingo'     => 'WinGo',
        'trxwingo'  => 'TrxWinGo',
        'trx'       => 'TrxWinGo',
        'k3'        => 'K3',
        'motorace'  => 'MotoRace',
        'moto'      => 'MotoRace',
    ];

    private const FAMILY_LABELS = [
        'WinGo'    => 'Win Go',
        'TrxWinGo' => 'TRX Win Go',
        'K3'       => 'K3 Lotre',
        'D5'       => '5D Lotre',
        'MotoRace' => 'Moto Race',
    ];

    /** @var array<string,GameDefinition> keyed by lowercase game code */
    private array $games = [];
    /** @var array<string,array{seconds:int,issue_code:string,label:string}> */
    private array $intervals;

    public function __construct(array $config)
    {
        $this->intervals = $config['intervals'] ?? [];
        $lockMap         = $config['betting']['lock_seconds'] ?? [];

        foreach ($config['games'] ?? [] as $entry) {
            $family   = (string) ($entry['lottery'] ?? '');
            $interval = strtoupper((string) ($entry['interval'] ?? ''));

            if (!isset(self::FAMILY_CODES[$family], $this->intervals[$interval])) {
                continue;
            }

            $meta = $this->intervals[$interval];
            $code = $family . '_' . $interval;

            $this->games[strtolower($code)] = new GameDefinition(
                $code,
                $family,
                self::FAMILY_CODES[$family],
                $interval,
                (string) $meta['issue_code'],
                (int) $meta['seconds'],
                (int) ($lockMap[$interval] ?? 5),
                (int) ($entry['sort'] ?? 0),
                (int) ($entry['state'] ?? 1),
                (self::FAMILY_LABELS[$family] ?? $family) . ' ' . $meta['label']
            );
        }
    }

    /** @return array<int,GameDefinition> ordered by sort */
    public function all(): array
    {
        $games = array_values($this->games);
        usort($games, static fn(GameDefinition $a, GameDefinition $b) => $a->sort <=> $b->sort);
        return $games;
    }

    /** @return array<string,array<int,GameDefinition>> family => games */
    public function grouped(): array
    {
        $grouped = [];
        foreach ($this->all() as $game) {
            $grouped[$game->family][] = $game;
        }
        return $grouped;
    }

    public function find(string $gameCode): ?GameDefinition
    {
        $normalised = $this->normaliseCode($gameCode);
        return $this->games[strtolower($normalised)] ?? null;
    }

    public function get(string $gameCode): GameDefinition
    {
        $game = $this->find($gameCode);
        if ($game === null) {
            throw ApiException::notFound("Unknown gameCode: {$gameCode}");
        }
        if ($game->state !== 1) {
            throw ApiException::closed("Game {$game->code} is currently disabled");
        }
        return $game;
    }

    /** Accepts "5D_1M", "wingo_1m", "WinGo1M" and normalises to canonical form. */
    public function normaliseCode(string $gameCode): string
    {
        $gameCode = trim($gameCode);
        if ($gameCode === '') {
            return '';
        }
        if (!str_contains($gameCode, '_')) {
            if (preg_match('/^([A-Za-z]+)(\d+[SM])$/i', $gameCode, $m)) {
                $gameCode = $m[1] . '_' . $m[2];
            } else {
                return $gameCode;
            }
        }

        [$family, $interval] = explode('_', $gameCode, 2);
        $family   = self::FAMILY_ALIASES[strtolower($family)] ?? $family;
        $interval = strtoupper($interval);

        return $family . '_' . $interval;
    }

    /** @return array<string,array{seconds:int,issue_code:string,label:string}> */
    public function intervals(): array
    {
        return $this->intervals;
    }

    public static function familyLabel(string $family): string
    {
        return self::FAMILY_LABELS[$family] ?? $family;
    }
}
