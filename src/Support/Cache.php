<?php
declare(strict_types=1);

namespace App\Support;

class Cache
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/var/cache';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
    }

    public function get(string $key): mixed
    {
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
        $file = $this->file($key);
        $payload = json_encode(['expires_at' => time() + $ttlSeconds, 'value' => $value]);
        @file_put_contents($file, $payload, LOCK_EX);
    }

    private function file(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) ?? 'cache';
        return rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . $safe . '.json';
    }
}
