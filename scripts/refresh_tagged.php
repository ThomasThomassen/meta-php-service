<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

App\Support\Env::load($root);

use App\Services\InstagramService;
use App\Support\BackgroundJobMonitor;
use App\Support\Env;

// Ensure STDERR exists for logging when running in constrained environments.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

// Parse simple CLI args: --per-page=, --max-pages=, --fields=
$perPage = 3;
$maxPages = 500;
$fields = null;
$jobFile = null;

foreach ($argv as $arg) {
    if (preg_match('/^--per-page=(\d{1,3})$/', $arg, $m)) {
        $perPage = max(1, min(50, (int)$m[1]));
    } elseif (preg_match('/^--max-pages=(\d{1,4})$/', $arg, $m)) {
        $maxPages = max(1, (int)$m[1]);
    } elseif (preg_match('/^--fields=(.+)$/', $arg, $m)) {
        $fields = trim($m[1]);
    } elseif (preg_match('/^--job-file=(.+)$/', $arg, $m)) {
        $jobFile = trim($m[1]);
    }
}

if (is_string($jobFile) && $jobFile !== '') {
    BackgroundJobMonitor::update($jobFile, [
        'status' => 'running',
        'started_at' => gmdate('c'),
        'pid' => getmypid() ?: null,
    ]);
}

$svc = new InstagramService();
try {
    $summary = $svc->refreshAllTaggedToFile($perPage, $maxPages, null, $fields);
    if (is_string($jobFile) && $jobFile !== '') {
        BackgroundJobMonitor::update($jobFile, [
            'status' => 'completed',
            'finished_at' => gmdate('c'),
            'summary' => $summary,
            'error' => null,
        ]);
    }
    echo "updated_at={$summary['updated_at']} count={$summary['count']}\n";
    exit(0);
} catch (Throwable $e) {
    if (is_string($jobFile) && $jobFile !== '') {
        BackgroundJobMonitor::update($jobFile, [
            'status' => 'failed',
            'finished_at' => gmdate('c'),
            'error' => $e->getMessage(),
        ]);
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(3);
}
