<?php
declare(strict_types=1);

namespace App\Support;

class Cache
{
    private string $dir;
    private int $cleanupInterval;
    private int $cleanupBatchSize;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/var/cache';
        $this->cleanupInterval = max(60, (int) (Env::get('CACHE_CLEANUP_INTERVAL_SECONDS', '1800') ?? '1800'));
        $this->cleanupBatchSize = max(50, (int) (Env::get('CACHE_CLEANUP_BATCH_SIZE', '200') ?? '200'));
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
    }

    public function get(string $key): mixed
    {
        $this->maybeCleanup();
        $file = $this->file($key);
        if (!is_file($file)) return null;
        $content = @file_get_contents($file);
        if ($content === false) return null;
        $data = json_decode($content, true);
        if (!is_array($data)) return null;
        if (($data['expires_at'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $data['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->maybeCleanup();
        $file = $this->file($key);
        $payload = json_encode(['expires_at' => time() + $ttlSeconds, 'value' => $value]);
        @file_put_contents($file, $payload, LOCK_EX);
    }

    private function file(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) ?? 'cache';
        return rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function maybeCleanup(): void
    {
        $marker = rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . '.cleanup.json';
        $lockFile = rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . '.cleanup.lock';
        $now = time();
        $lastRun = 0;

        if (is_file($marker)) {
            $raw = @file_get_contents($marker);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $lastRun = (int) ($data['last_run'] ?? 0);
                }
            }
        }

        if ($lastRun > 0 && ($now - $lastRun) < $this->cleanupInterval) {
            return;
        }

        $lock = @fopen($lockFile, 'c+');
        if ($lock === false) {
            return;
        }

        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            @fclose($lock);
            return;
        }

        try {
            $deleted = 0;
            $checked = 0;
            $entries = @scandir($this->dir);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                        continue;
                    }

                    $path = rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . $entry;
                    if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'json') {
                        continue;
                    }

                    $checked++;
                    $raw = @file_get_contents($path);
                    if (is_string($raw) && $raw !== '') {
                        $data = json_decode($raw, true);
                        if (is_array($data) && isset($data['expires_at']) && (int) $data['expires_at'] < $now) {
                            if (@unlink($path)) {
                                $deleted++;
                            }
                        }
                    }

                    if ($checked >= $this->cleanupBatchSize) {
                        break;
                    }
                }
            }

            @file_put_contents($marker, json_encode([
                'last_run' => $now,
                'deleted' => $deleted,
                'checked' => $checked,
            ]), LOCK_EX);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}
