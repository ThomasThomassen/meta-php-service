<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Tiny file-based scheduler to run lightweight tasks at most once per window.
 * Uses a lock file to avoid concurrent executions.
 */
class Scheduler
{
    private string $dir;

    public function __construct(?string $directory = null)
    {
        $this->dir = $directory ?: dirname(__DIR__, 2) . '/var/cache';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
    }

    /**
     * Try to run a named task if its window has elapsed.
     * Returns true if the task ran (successfully or not), false if skipped.
     */
    public function tryRun(string $taskName, int $windowSeconds, callable $fn): bool
    {
        $safe = preg_replace('/[^a-z0-9_.-]+/i', '_', $taskName) ?: 'task';
        $statusFile = $this->dir . '/sched_' . $safe . '.json';
        $lockFile = $statusFile . '.lock';

        $lockHandle = @fopen($lockFile, 'c+');
        if (!$lockHandle) {
            return false; // cannot lock
        }
        $locked = @flock($lockHandle, LOCK_EX | LOCK_NB);
        if (!$locked) {
            @fclose($lockHandle);
            return false; // another process is handling it
        }

        $now = time();
        $data = [];
        if (is_file($statusFile)) {
            $raw = @file_get_contents($statusFile);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true) ?: [];
            }
        }
        $lastRun = (int)($data['last_run'] ?? 0);
        if ($lastRun > 0 && ($now - $lastRun) < $windowSeconds) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
            return false; // within window; skip
        }

        // Mark running and tentative last_run
        $data['last_run'] = $now;
        $data['running'] = true;
        @file_put_contents($statusFile, json_encode($data));

        try {
            $fn();
            $data['last_ok'] = $now;
            $data['last_error'] = null;
        } catch (\Throwable $e) {
            $data['last_error'] = $e->getMessage();
            // swallow error; scheduler should never break main request
        } finally {
            $data['running'] = false;
            @file_put_contents($statusFile, json_encode($data));
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }

        return true;
    }
}
