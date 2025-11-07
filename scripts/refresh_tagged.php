<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

App\Support\Env::load($root);

use App\Services\InstagramService;
use App\Support\Env;

// Parse simple CLI args: --per-page=, --max-pages=, --fields=
$perPage = 3;
$maxPages = 500;
$fields = null;

foreach ($argv as $arg) {
    if (preg_match('/^--per-page=(\d{1,3})$/', $arg, $m)) {
        $perPage = max(1, min(50, (int)$m[1]));
    } elseif (preg_match('/^--max-pages=(\d{1,4})$/', $arg, $m)) {
        $maxPages = max(1, (int)$m[1]);
    } elseif (preg_match('/^--fields=(.+)$/', $arg, $m)) {
        $fields = trim($m[1]);
    }
}

$svc = new InstagramService();
try {
    $summary = $svc->refreshAllTaggedToFile($perPage, $maxPages, null, $fields);
    echo "updated_at={$summary['updated_at']} count={$summary['count']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(3);
}
