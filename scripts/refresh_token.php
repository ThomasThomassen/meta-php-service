<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

App\Support\Env::load($root);

use App\Services\TokenService;
use App\Support\Env;
use App\Support\SecretStore;

$thresholdDays = (int) (Env::get('REFRESH_THRESHOLD_DAYS', '30') ?? '30');
$storage = Env::get('IG_TOKEN_STORAGE');
$token = null;
if ($storage && is_readable($storage)) {
    $token = trim((string) @file_get_contents($storage)) ?: null;
}
if (!$token) {
    $token = Env::get('IG_ACCESS_TOKEN');
}
if (!$token) {
    fwrite(STDERR, "No token found. Set IG_ACCESS_TOKEN or IG_TOKEN_STORAGE.\n");
    exit(1);
}

$svc = new TokenService();
try {
    $info = $svc->debugToken($token);
    $now = time();
    $expiresAt = (int) ($info['expires_at'] ?? 0);
    $expiresIn = (int) ($info['expires_in'] ?? max(0, $expiresAt - $now));
    $shouldRefresh = false;
    if ($expiresAt > 0) {
        $shouldRefresh = ($expiresAt - $now) <= ($thresholdDays * 86400);
    } else {
        $shouldRefresh = $expiresIn <= ($thresholdDays * 86400);
    }
    echo "expires_at=" . ($expiresAt ?: 'unknown') . " expires_in=" . ($expiresIn ?: 'unknown') . " should_refresh=" . ($shouldRefresh ? 'yes' : 'no') . "\n";
    if ($shouldRefresh) {
        $res = $svc->refreshLongLived($token);
        $new = $res['access_token'] ?? null;
        if ($new) {
            if ($storage) {
                SecretStore::write($storage, $new);
                echo "refreshed and stored\n";
            } else {
                echo "refreshed; update IG_ACCESS_TOKEN in .env\n";
                echo $new . "\n";
            }
            exit(0);
        }
        fwrite(STDERR, "Refresh did not return access_token.\n");
        exit(2);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(3);
}
