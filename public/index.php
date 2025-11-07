<?php
declare(strict_types=1);
use App\Support\Env;
use App\Support\Response;
use App\Support\Router;
use App\Support\Logger;
use App\Support\RateLimiter;
use App\Support\Scheduler;

$__isCliServer = PHP_SAPI === 'cli-server';
if ($__isCliServer) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if (is_file($file)) {
        // Let the built-in server serve static files directly
        return false;
    }
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
// Early handlers before Composer autoload (in case autoload triggers an error)
set_error_handler(function ($severity, $message, $file = null, $line = null) {
    // Convert errors to text output to aid debugging 500s during bootstrap
    if (!(error_reporting() & $severity)) { return; }
    if (!headers_sent()) header('Content-Type: text/plain');
    http_response_code(500);
    echo "bootstrap_error: $message\n$file:$line\n";
    error_log("bootstrap_error: $message in $file:$line");
    exit(1);
});
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) header('Content-Type: text/plain');
    http_response_code(500);
    echo "bootstrap_exception: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    error_log('bootstrap_exception: ' . $e->getMessage());
    exit(1);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: text/plain');
        http_response_code(500);
        echo 'bootstrap_fatal: ' . ($err['message'] ?? 'fatal') . "\n" . ($err['file'] ?? '') . ':' . ($err['line'] ?? 0) . "\n";
        error_log('bootstrap_fatal: ' . ($err['message'] ?? 'fatal'));
    }
});
if (!file_exists($autoload)) {
    if (!headers_sent()) header('Content-Type: text/plain');
    http_response_code(500);
    echo "bootstrap_failed: Missing vendor autoload. Run composer install in project root.\n";
    exit;
}
require $autoload;

// Basic error handling (register as early as possible)
set_exception_handler(function (Throwable $e) {
    Logger::error($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine());
    echo Response::json(['error' => 'internal_error', 'message' => $e->getMessage()], 500);
});

// Capture fatal errors
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        Logger::error(($err['message'] ?? 'fatal') . "\n" . ($err['file'] ?? '') . ':' . ($err['line'] ?? 0));
        echo Response::json(['error' => 'fatal_error', 'message' => 'A server error occurred. Check logs.'], 500);
    }
});

// Load environment (after handlers so parse errors get logged)
try {
    App\Support\Env::load(dirname(__DIR__));
} catch (Throwable $e) {
    Logger::error('Env load failed: ' . $e->getMessage());
    echo Response::json(['error' => 'env_error', 'message' => 'Failed loading environment file.'], 500);
    exit;
}

// Optional: lightweight daily token auto-refresh, triggered opportunistically by requests
// Commented out - i setup a cron job to /auth/token/auto-refresh?admin_token=... instead
/* try {
    if ((int) (Env::get('AUTO_REFRESH_ENABLED', '0') ?? '0') === 1) {
        $window = (int) (Env::get('AUTO_REFRESH_WINDOW_SECONDS', '86400') ?? '86400');
        $scheduler = new Scheduler(dirname(__DIR__) . '/var/cache');
        $scheduler->tryRun('token_auto_refresh', max(60, $window), function () {
            $thresholdDays = (int) (App\Support\Env::get('REFRESH_THRESHOLD_DAYS', '30') ?? '30');
            $token = App\Support\Env::get('IG_ACCESS_TOKEN') ?? '';
            $storage = App\Support\Env::get('IG_TOKEN_STORAGE');
            if ($storage && is_readable($storage)) {
                $fromFile = trim((string) @file_get_contents($storage));
                if ($fromFile !== '') $token = $fromFile;
            }
            if ($token === '') {
                return; // nothing to do
            }
            $svc = new App\Services\TokenService();
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
            if ($shouldRefresh) {
                $svc->refreshLongLived($token); // persists to IG_TOKEN_STORAGE if configured
            }
        });
    }
} catch (Throwable $e) {
    Logger::error('scheduler_error: ' . $e->getMessage());
} */

// CORS handling (allow only whitelisted origins)
App\Support\Cors::handle();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Preflight handled in Cors::handle
    exit;
}

// Apply a simple per-IP rate limit to public Instagram endpoints
$__path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($__path, '/instagram')) {
    $limiter = new RateLimiter();
    [$allowed, $limit, $remaining, $retryAfter] = $limiter->allow('instagram');
    header('RateLimit-Limit: ' . $limit);
    header('RateLimit-Remaining: ' . max(0, $remaining));
    if (!$allowed) {
        header('Retry-After: ' . max(1, (int) $retryAfter));
        echo Response::json([
            'error' => 'rate_limited',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => (int) $retryAfter,
        ], 429);
        exit;
    }
}

// Routing
$router = new Router();

$router->get('/', function () {
    return Response::json([
        'service' => 'meta-php-service',
        'status' => 'ok',
        'version' => '1.0.0',
        'time' => date(DATE_ATOM)
    ]);
});


// Health check
$router->get('/health', function () {
    return Response::json(['status' => 'ok', 'time' => gmdate('c'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
});

// Simple ping route (useful in dev/prod without relying on a physical file)
$router->get('/ping', function () {
    return Response::json(['pong' => true, 'php' => PHP_VERSION]);
});

// Instagram hashtag endpoint
// Example: /instagram/hashtag?tag=sunset&limit=10&type=recent
$router->get('/instagram/hashtag', [App\Controllers\InstagramController::class, 'getHashtagMedia']);

// Instagram: own posts and tagged posts
$router->get('/instagram/self/media', [App\Controllers\InstagramController::class, 'getSelfMedia']);
$router->get('/instagram/self/media/local', [App\Controllers\InstagramController::class, 'getLocalUserMedia']);
$router->get('/instagram/self/media/refresh-all', [App\Controllers\InstagramController::class, 'refreshAllUserMedia']);
$router->get('/instagram/self/media/refresh-all-async', [App\Controllers\InstagramController::class, 'refreshAllUserMediaAsync']);
$router->get('/instagram/self/tags', [App\Controllers\InstagramController::class, 'getTaggedMedia']);
$router->get('/instagram/self/merged', [App\Controllers\InstagramController::class, 'getMergedMedia']);
// Fetch children for a specific media id (e.g., carousel album)
$router->get('/instagram/self/tags/getchildren', [App\Controllers\InstagramController::class, 'getChildrenByMediaId']);
// Offline/local tagged data queries
$router->get('/instagram/self/tags/local', [App\Controllers\InstagramController::class, 'getLocalTagged']);
// Refresh and persist all tagged posts (admin restricted)
$router->get('/instagram/self/tags/refresh-all', [App\Controllers\InstagramController::class, 'refreshAllTagged']);
// Async refresh trigger (admin restricted)
$router->get('/instagram/self/tags/refresh-all-async', [App\Controllers\InstagramController::class, 'refreshAllTaggedAsync']);

// Token utilities (restricted by IP or admin token)
$router->get('/auth/token/debug', [App\Controllers\AuthController::class, 'debug']);
$router->get('/auth/token/refresh', [App\Controllers\AuthController::class, 'refresh']);
$router->get('/auth/token/auto-refresh', [App\Controllers\AuthController::class, 'autoRefresh']);

// Optional diagnostics (enable by setting DIAGNOSTICS_ENABLED=1 in .env)a
$router->get('/diagnostics', function () {
    if ((int) (App\Support\Env::get('DIAGNOSTICS_ENABLED', '0') ?? '0') !== 1) {
        return Response::json(['error' => 'forbidden'], 403);
    }
    return Response::json([
        'php_version' => PHP_VERSION,
        'extensions' => [
            'curl' => extension_loaded('curl'),
            'openssl' => extension_loaded('openssl'),
            'json' => extension_loaded('json'),
        ],
        'paths' => [
            'project_root' => dirname(__DIR__),
            'vendor_autoload' => file_exists(dirname(__DIR__) . '/vendor/autoload.php'),
        ],
    ]);
});

// Dispatch
echo $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
