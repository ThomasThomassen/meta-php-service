<?php
declare(strict_types=1);

namespace App\Support;

class Response
{
    public static function json(array $data, int $status = 200): string
    {
        http_response_code($status);
        if (function_exists('json_encode')) {
            header('Content-Type: application/json');
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                if (self::applyJsonCaching($json, $status)) {
                    return '';
                }
                return $json;
            }
        }
        // Fallback to text/plain if JSON extension is missing or encoding failed
        header('Content-Type: text/plain');
        $lines = [];
        foreach ($data as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $lines[] = $k . ': ' . (string) $v;
            } else {
                $lines[] = $k . ': [complex value]';
            }
        }
        return implode("\n", $lines);
    }

    private static function applyJsonCaching(string $json, int $status): bool
    {
        if (!self::shouldCacheJsonResponse($status)) {
            header('Cache-Control: no-store, private');
            return false;
        }

        $ttl = max(0, (int) (Env::get('JSON_CACHE_TTL_SECONDS', '300') ?? '300'));
        if ($ttl <= 0) {
            header('Cache-Control: no-store, private');
            return false;
        }

        $etag = '"' . sha1($json) . '"';
        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

        header('Cache-Control: public, max-age=' . $ttl . ', stale-while-revalidate=60');
        header('ETag: ' . $etag);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
        header('Vary: Origin');

        if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
            http_response_code(304);
            return true;
        }

        return false;
    }

    private static function shouldCacheJsonResponse(int $status): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }

        if ($status < 200 || $status >= 300) {
            return false;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        if (!str_starts_with($path, '/instagram')) {
            return false;
        }

        if ($path === '/instagram/media') {
            return false;
        }

        return true;
    }
}
