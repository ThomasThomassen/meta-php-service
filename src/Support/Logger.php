<?php
declare(strict_types=1);

namespace App\Support;

class Logger
{
    public static function error(string $message): void
    {
        $logDir = dirname(__DIR__, 2) . '/var/log';
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        $file = $logDir . '/app.log';
        $line = '[' . gmdate('c') . '] ERROR ' . $message . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
