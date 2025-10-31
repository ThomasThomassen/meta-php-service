<?php
declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;

class Env
{
    public static function load(string $basePath): void
    {
        if (file_exists($basePath . '/.env')) {
            $dotenv = Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    public static function isLocal(): bool
    {
        // PHP built-in server
        if (PHP_SAPI === 'cli-server') {
            return true;
        }
        // Loopback addresses
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote === '127.0.0.1' || $remote === '::1') {
            return true;
        }
        // Common localhost hostnames
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        if ($host === 'localhost' || str_ends_with((string)$host, '.localhost')) {
            return true;
        }
        return false;
    }
}
