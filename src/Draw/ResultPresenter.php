<?php

declare(strict_types=1);

namespace Lottery\Draw;

/**
 * Shapes a stored result row for API responses.
 */
final class ResultPresenter
{
    public static function present(array $row): array
    {
        $result = $row['result'] ?? [];

        $base = [
            'gameCode'    => $row['game_code'] ?? null,
            'issueNumber' => $row['issue_number'] ?? null,
            'family'      => $row['family'] ?? ($result['family'] ?? null),
            'source'      => $row['source'] ?? null,
            'drawnAt'     => $row['drawn_at'] ?? null,
            'drawHash'    => $row['draw_hash'] ?? null,
        ];

        switch ($base['family']) {
            case 'WinGo':
            case 'TrxWinGo':
                $base += [
                    'number'      => (int) ($result['number'] ?? 0),
                    'color'       => $result['color'] ?? '',
                    'colors'      => $result['colors'] ?? [],
                    'size'        => $result['size'] ?? '',
                    'parity'      => $result['parity'] ?? '',
                    'blockHash'   => $result['blockHash'] ?? null,
                    'blockHeight' => $result['blockHeight'] ?? null,
                ];
                break;

            case 'K3':
                $base += [
                    'dice'   => $result['dice'] ?? [],
                    'sum'    => (int) ($result['sum'] ?? 0),
                    'size'   => $result['size'] ?? '',
                    'parity' => $result['parity'] ?? '',
                    'triple' => (bool) ($result['triple'] ?? false),
                ];
                break;

            case 'D5':
                $base += [
                    'digits' => $result['digits'] ?? [],
                    'code'   => $result['code'] ?? '',
                    'sum'    => (int) ($result['sum'] ?? 0),
                    'size'   => $result['size'] ?? '',
                    'parity' => $result['parity'] ?? '',
                ];
                break;

            case 'MotoRace':
                $base += [
                    'ranking'  => $result['ranking'] ?? [],
                    'champion' => (int) ($result['champion'] ?? 0),
                    'podium'   => $result['podium'] ?? [],
                    'size'     => $result['size'] ?? '',
                    'parity'   => $result['parity'] ?? '',
                ];
                break;

            default:
                $base['raw'] = $result;
        }

        if (isset($result['verify'])) {
            $base['verify'] = $result['verify'];
        }

        return $base;
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function presentMany(array $rows): array
    {
        return array_map([self::class, 'present'], $rows);
    }
}
