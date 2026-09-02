<?php

declare(strict_types=1);

namespace Lottery\Games\Families;

/**
 * TRX WinGo — identical betting surface to WinGo, but the digit is derived
 * from a TRON block hash. When the provider supplies the hash we take the last
 * numeric character of it; the local fallback derives both hash and digit from
 * the HMAC draw seed so results stay verifiable.
 */
class TrxWinGoRules extends WinGoRules
{
    public function family(): string
    {
        return 'TrxWinGo';
    }

    public function generate(callable $rng): array
    {
        $result           = $this->build($rng(0, 9));
        $result['family'] = $this->family();
        return $result;
    }

    public function fromOverride(string $value): array
    {
        $result           = parent::fromOverride($value);
        $result['family'] = $this->family();
        return $result;
    }

    public function fromProvider(array $row): ?array
    {
        $result = parent::fromProvider($row);

        if ($result === null) {
            // Derive from the block hash when no explicit number is present.
            $hash = $this->pick($row, ['blockHash', 'hash', 'block_hash', 'tradeHash']);
            if ($hash === null) {
                return null;
            }
            $digits = preg_replace('/\D/', '', $hash) ?? '';
            if ($digits === '') {
                return null;
            }
            $result = $this->build((int) substr($digits, -1));
        }

        $result['family']      = $this->family();
        $result['blockHash']   = $this->pick($row, ['blockHash', 'hash', 'block_hash', 'tradeHash']);
        $result['blockHeight'] = $this->pick($row, ['blockHeight', 'height', 'block_number']);

        return $result;
    }

    /** Attach a synthetic-but-deterministic chain reference to a local draw. */
    public function withChainReference(array $result, string $seedHash, int $height): array
    {
        $result['blockHash']   = substr($seedHash, 0, 64);
        $result['blockHeight'] = $height;
        return $result;
    }
}
