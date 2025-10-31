<?php
declare(strict_types=1);

namespace App\Support;

class SecretStore
{
    /**
     * Read secret token from a file path if readable.
     */
    public static function read(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $v = @file_get_contents($path);
        if ($v === false) return null;
        $v = trim($v);
        return $v !== '' ? $v : null;
    }

    /**
     * Write secret token to a file path, creating directories as needed.
     */
    public static function write(string $path, string $value): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return @file_put_contents($path, $value, LOCK_EX) !== false;
    }
}
