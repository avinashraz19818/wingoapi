<?php

declare(strict_types=1);

namespace Lottery\Support;

/**
 * Fixed-window rate limiter backed by the filesystem (no Redis dependency).
 * Each {key, window} pair gets one small counter file guarded by flock().
 */
final class RateLimiter
{
    private string $dir;
    private int $limit;
    private int $window;

    public function __construct(string $dir, int $limit = 120, int $window = 60)
    {
        $this->dir    = rtrim($dir, '/');
        $this->limit  = max(1, $limit);
        $this->window = max(1, $window);

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    /**
     * @return array{allowed:bool,remaining:int,reset:int,limit:int}
     */
    public function hit(string $key): array
    {
        $now    = Clock::now();
        $bucket = (int) floor($now / $this->window);
        $reset  = ($bucket + 1) * $this->window;
        $file   = $this->dir . '/' . hash('sha256', $key . '|' . $bucket) . '.cnt';

        $count = 1;
        $fh    = @fopen($file, 'c+');
        if ($fh === false) {
            // Fail open rather than break the API if the tmp dir is unwritable.
            return ['allowed' => true, 'remaining' => $this->limit, 'reset' => $reset, 'limit' => $this->limit];
        }
        if (flock($fh, LOCK_EX)) {
            $raw   = stream_get_contents($fh) ?: '';
            $count = ((int) trim($raw)) + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) $count);
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);

        $this->gc();

        return [
            'allowed'   => $count <= $this->limit,
            'remaining' => max(0, $this->limit - $count),
            'reset'     => $reset,
            'limit'     => $this->limit,
        ];
    }

    /** Probabilistic cleanup of expired counter files. */
    private function gc(): void
    {
        if (random_int(1, 200) !== 1) {
            return;
        }
        $cutoff = Clock::now() - ($this->window * 3);
        foreach (glob($this->dir . '/*.cnt') ?: [] as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
