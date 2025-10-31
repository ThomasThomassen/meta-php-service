<?php
declare(strict_types=1);

namespace App\Support;

class RateLimiter
{
    private string $storage;
    private int $window;
    private int $max;
    private bool $trustProxy;

    public function __construct(?string $storagePath = null, ?int $windowSeconds = null, ?int $maxRequests = null, ?bool $trustProxy = null)
    {
        $root = dirname(__DIR__, 2);
        $this->storage = rtrim($storagePath ?? (Env::get('RATE_LIMIT_STORAGE', $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ratelimit') ?? ($root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'ratelimit')), '\/');
        $this->window = $windowSeconds ?? (int) (Env::get('RATE_LIMIT_WINDOW_SECONDS', '60') ?? '60');
        $this->max = $maxRequests ?? (int) (Env::get('RATE_LIMIT_MAX_REQUESTS', '60') ?? '60');
        $this->trustProxy = $trustProxy ?? ((int) (Env::get('RATE_LIMIT_TRUST_PROXY', '0') ?? '0') === 1);

        // Ensure base storage directory exists
        if (!is_dir($this->storage)) {
            @mkdir($this->storage, 0777, true);
        }
    }

    public function getClientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->trustProxy) {
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($xff) {
                // First IP in the list is the original client
                $first = trim(explode(',', $xff)[0] ?? '');
                if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                    $ip = $first;
                }
            }
        }
        return $ip;
    }

    /**
     * Attempt to consume one request from the bucket.
     * Returns [allowed, limit, remaining, retryAfterSeconds].
     */
    public function allow(string $group, ?string $ip = null): array
    {
        $ip = $ip ?? $this->getClientIp();
        $safeIp = preg_replace('~[^a-zA-Z0-9_\.-]~', '_', $ip);
        $groupDir = $this->storage . DIRECTORY_SEPARATOR . $group;
        if (!is_dir($groupDir)) {
            @mkdir($groupDir, 0777, true);
        }
        $file = $groupDir . DIRECTORY_SEPARATOR . $safeIp . '.json';

        $now = time();
        $windowStart = $now;
        $count = 0;

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            // If storage is not writable, fail-open (allow) to avoid blocking traffic.
            return [true, $this->max, $this->max - 1, 0];
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                // If lock fails, also fail-open
                fclose($fp);
                return [true, $this->max, $this->max - 1, 0];
            }
            // Read existing state
            rewind($fp);
            $raw = stream_get_contents($fp);
            if ($raw) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $windowStart = (int) ($data['window_start'] ?? $now);
                    $count = (int) ($data['count'] ?? 0);
                }
            }

            // Reset window if expired
            if ($now - $windowStart >= $this->window) {
                $windowStart = $now;
                $count = 0;
            }

            // Check allowance
            if ($count >= $this->max) {
                $retryAfter = max(1, $this->window - ($now - $windowStart));
                // Keep state as-is
                $remaining = 0;
                $this->persist($fp, $windowStart, $count);
                flock($fp, LOCK_UN);
                fclose($fp);
                return [false, $this->max, $remaining, $retryAfter];
            }

            // Consume one
            $count++;
            $remaining = max(0, $this->max - $count);
            $this->persist($fp, $windowStart, $count);
            flock($fp, LOCK_UN);
            fclose($fp);
            return [true, $this->max, $remaining, max(0, $this->window - ($now - $windowStart))];
        } catch (\Throwable $e) {
            try { flock($fp, LOCK_UN); } catch (\Throwable $e2) {}
            fclose($fp);
            // Fail-open on unexpected errors
            return [true, $this->max, $this->max - 1, 0];
        }
    }

    private function persist($fp, int $windowStart, int $count): void
    {
        $payload = json_encode(['window_start' => $windowStart, 'count' => $count]);
        if ($payload === false) {
            return;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $payload);
        fflush($fp);
    }
}
