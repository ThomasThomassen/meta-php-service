<?php
declare(strict_types=1);

namespace App\Support;

class BackgroundJobMonitor
{
    /**
     * @return array{job_file:string,lock_file:string}|null
     */
    public static function tryStart(string $jobName, array $data): ?array
    {
        $file = self::jobFile($jobName);
        $lockFile = self::lockFile($jobName);
        $handle = @fopen($lockFile, 'c+');
        if ($handle === false) {
            return null;
        }

        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);
            return null;
        }

        try {
            $existing = self::read($file);
            if (is_array($existing) && self::isActive($existing)) {
                return null;
            }

            self::write($file, array_merge($existing ?? [], $data, [
                'job_name' => $jobName,
                'status' => $data['status'] ?? 'queued',
                'lock_file' => $lockFile,
                'updated_at' => gmdate('c'),
            ]));
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }

        return ['job_file' => $file, 'lock_file' => $lockFile];
    }

    public static function update(string $jobFile, array $data): void
    {
        $current = self::read($jobFile) ?? [];

        self::write($jobFile, array_merge($current, $data, [
            'updated_at' => gmdate('c'),
        ]));

        $status = $data['status'] ?? null;
        if ($status === 'completed' || $status === 'failed') {
            self::releaseLockByJobFile($jobFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function diagnostics(): array
    {
        $dir = self::directory();
        $files = glob($dir . DIRECTORY_SEPARATOR . 'job_*.json') ?: [];
        $jobs = [];

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                continue;
            }

            $jobs[] = self::describe($file, $decoded);
        }

        usort($jobs, static function (array $left, array $right): int {
            return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        });

        return [
            'note' => 'Tracks app-managed async refresh jobs started by this service. System cron jobs are not enumerated.',
            'tracked' => $jobs,
        ];
    }

    public static function directory(): string
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'jobs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }

    public static function jobFile(string $jobName): string
    {
        $safe = preg_replace('/[^a-z0-9_.-]+/i', '_', $jobName) ?: 'job';
        return self::directory() . DIRECTORY_SEPARATOR . 'job_' . $safe . '.json';
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private static function describe(string $jobFile, array $job): array
    {
        $pid = isset($job['pid']) && is_numeric((string) $job['pid']) ? (int) $job['pid'] : null;
        $logFile = isset($job['log_file']) && is_string($job['log_file']) ? $job['log_file'] : null;
        $startedAt = self::toTimestamp($job['started_at'] ?? null);
        $finishedAt = self::toTimestamp($job['finished_at'] ?? null);
        $updatedAt = self::toTimestamp($job['updated_at'] ?? null);
        $isRunning = self::isRunning($pid, $job);

        return [
            'job_name' => $job['job_name'] ?? basename($jobFile, '.json'),
            'job_file' => $jobFile,
            'lock_file' => $job['lock_file'] ?? self::lockFile((string) ($job['job_name'] ?? basename($jobFile, '.json'))),
            'status' => $job['status'] ?? 'unknown',
            'pid' => $pid,
            'pid_running' => $isRunning,
            'started_at' => $job['started_at'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
            'updated_at' => $job['updated_at'] ?? null,
            'elapsed_seconds' => $startedAt === null ? null : (($finishedAt ?? time()) - $startedAt),
            'request' => $job['request'] ?? null,
            'summary' => $job['summary'] ?? null,
            'error' => $job['error'] ?? null,
            'log' => [
                'path' => $logFile,
                'exists' => is_string($logFile) ? is_file($logFile) : false,
                'updated_at' => is_string($logFile) && is_file($logFile) ? gmdate('c', (int) @filemtime($logFile)) : null,
                'size_bytes' => is_string($logFile) && is_file($logFile) ? ((@filesize($logFile) === false) ? null : (int) @filesize($logFile)) : null,
                'tail' => is_string($logFile) ? self::tail($logFile, 10) : [],
            ],
            'stale' => ($finishedAt === null && !$isRunning && $updatedAt !== null),
        ];
    }

    public static function lockFile(string $jobName): string
    {
        $safe = preg_replace('/[^a-z0-9_.-]+/i', '_', $jobName) ?: 'job';
        return self::directory() . DIRECTORY_SEPARATOR . 'job_' . $safe . '.lock';
    }

    public static function releaseLockByJobFile(string $jobFile): void
    {
        $job = self::read($jobFile);
        $jobName = is_array($job) && isset($job['job_name']) ? (string) $job['job_name'] : basename($jobFile, '.json');
        $lockFile = is_array($job) && isset($job['lock_file']) && is_string($job['lock_file'])
            ? $job['lock_file']
            : self::lockFile($jobName);
        @unlink($lockFile);
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function isRunning(?int $pid, array $job): ?bool
    {
        if (($job['status'] ?? null) === 'completed' || ($job['status'] ?? null) === 'failed') {
            return false;
        }
        if ($pid === null || $pid <= 0) {
            return null;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        if (function_exists('shell_exec')) {
            $output = @shell_exec('ps -p ' . (int) $pid . ' -o pid=');
            return is_string($output) && trim($output) !== '';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function isActive(array $job): bool
    {
        $status = (string) ($job['status'] ?? '');
        if ($status === 'completed' || $status === 'failed') {
            return false;
        }

        $pid = isset($job['pid']) && is_numeric((string) $job['pid']) ? (int) $job['pid'] : null;
        $running = self::isRunning($pid, $job);
        if ($running === true) {
            return true;
        }
        if ($running === false) {
            return false;
        }

        $updatedAt = self::toTimestamp($job['updated_at'] ?? null);
        if ($updatedAt === null) {
            return true;
        }

        return (time() - $updatedAt) < 21600;
    }

    private static function toTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int, string>
     */
    private static function tail(string $file, int $maxLines): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        return array_values(array_slice($lines, -$maxLines));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function write(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}