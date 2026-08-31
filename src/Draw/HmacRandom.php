<?php

declare(strict_types=1);

namespace Lottery\Draw;

/**
 * Deterministic, uniformly-distributed random stream derived from
 * HMAC-SHA256(secret, seed). The same seed always yields the same sequence,
 * which makes locally generated draws reproducible and auditable.
 */
final class HmacRandom
{
    private string $secret;
    private string $seed;
    private int $counter = 0;
    private string $buffer = '';

    public function __construct(string $secret, string $seed)
    {
        $this->secret = $secret;
        $this->seed   = $seed;
    }

    /** The commitment hash a player can verify once the secret is published. */
    public function seedHash(): string
    {
        return hash_hmac('sha256', $this->seed, $this->secret);
    }

    /** Uniform integer in [$min, $max] via rejection sampling. */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        $range = $max - $min + 1;
        $limit = intdiv(PHP_INT_MAX, $range) * $range;

        do {
            $value = $this->nextUint32();
        } while ($value >= $limit);

        return $min + ($value % $range);
    }

    /** @return callable(int,int):int suitable for FamilyRules::generate() */
    public function callable(): callable
    {
        return function (int $min, int $max): int {
            return $this->int($min, $max);
        };
    }

    private function nextUint32(): int
    {
        if (strlen($this->buffer) < 4) {
            $this->buffer .= hash_hmac('sha256', $this->seed . '|' . $this->counter++, $this->secret, true);
        }
        $chunk        = substr($this->buffer, 0, 4);
        $this->buffer = substr($this->buffer, 4);

        /** @var array{1:int} $parts */
        $parts = unpack('N', $chunk);

        return $parts[1];
    }
}
