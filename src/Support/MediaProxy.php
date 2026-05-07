<?php
declare(strict_types=1);

namespace App\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MediaProxy
{
    public static function isEnabled(): bool
    {
        return (int) (Env::get('MEDIA_PROXY_ENABLED', '0') ?? '0') === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function rewriteItems(array $items): array
    {
        if (!self::isEnabled()) {
            return $items;
        }

        return array_map(fn (array $item) => self::rewriteItem($item), $items);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function rewriteItem(array $item): array
    {
        if (!self::isEnabled()) {
            return $item;
        }

        $thumbnailUrl = isset($item['thumbnail_url']) && is_string($item['thumbnail_url']) ? $item['thumbnail_url'] : null;
        $mediaUrl = isset($item['media_url']) && is_string($item['media_url']) ? $item['media_url'] : null;

        if (self::preferVideoPosterImage($item) && $thumbnailUrl !== null && $thumbnailUrl !== '') {
            if (($item['video_url'] ?? null) === null && $mediaUrl !== null && $mediaUrl !== '') {
                $item['video_url'] = $mediaUrl;
            }
            $item['media_url'] = self::buildProxyUrl($thumbnailUrl);
        } elseif ($mediaUrl !== null) {
            $item['media_url'] = self::buildProxyUrl($mediaUrl);
        }

        if ($thumbnailUrl !== null && $thumbnailUrl !== '') {
            $item['thumbnail_url'] = self::buildProxyUrl($thumbnailUrl);
        }

        if (isset($item['video_url']) && is_string($item['video_url']) && $item['video_url'] !== '') {
            $item['video_url'] = self::buildProxyUrl($item['video_url']);
        }

        if (isset($item['children']) && is_array($item['children'])) {
            $children = [];
            foreach ($item['children'] as $child) {
                if (!is_array($child)) {
                    $children[] = $child;
                    continue;
                }
                $children[] = self::rewriteItem($child);
            }
            $item['children'] = $children;
        }

        return $item;
    }

    public static function serveFromRequest(): string
    {
        $url = trim((string) ($_GET['url'] ?? ''));
        if ($url === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            return json_encode(['error' => 'missing_url'], JSON_UNESCAPED_SLASHES) ?: '{"error":"missing_url"}';
        }

        if (!self::isAllowedRemoteUrl($url)) {
            Logger::warning('media_proxy_rejected_url: ' . self::sanitizeForLogs($url));
            http_response_code(400);
            header('Content-Type: application/json');
            return json_encode(['error' => 'invalid_media_url'], JSON_UNESCAPED_SLASHES) ?: '{"error":"invalid_media_url"}';
        }

        $ttl = max(60, (int) (Env::get('MEDIA_PROXY_TTL_SECONDS', '86400') ?? '86400'));
        $maxBytes = max(1048576, (int) (Env::get('MEDIA_PROXY_MAX_BYTES', '52428800') ?? '52428800'));
        $cacheDir = self::cacheDir();
        $key = sha1($url);
        $bodyFile = $cacheDir . DIRECTORY_SEPARATOR . $key . '.bin';
        $metaFile = $cacheDir . DIRECTORY_SEPARATOR . $key . '.json';

        $meta = self::readMeta($metaFile);
        if (self::isFresh($meta, $bodyFile, $ttl)) {
            return self::emitCachedFile($bodyFile, $meta, $ttl);
        }

        try {
            $fetched = self::fetchRemoteToCache($url, $bodyFile, $metaFile, $maxBytes);
        } catch (\RuntimeException $e) {
            Logger::warning('media_proxy_fetch_failed: ' . self::sanitizeForLogs($e->getMessage()));
            if (is_array($meta) && is_file($bodyFile)) {
                return self::emitCachedFile($bodyFile, $meta, max(60, (int) floor($ttl / 10)));
            }

            http_response_code(502);
            header('Content-Type: application/json');
            return json_encode(['error' => 'media_proxy_fetch_failed'], JSON_UNESCAPED_SLASHES) ?: '{"error":"media_proxy_fetch_failed"}';
        }

        return self::emitCachedFile($bodyFile, $fetched, $ttl);
    }

    public static function buildProxyUrl(string $url): string
    {
        if (!self::isEnabled() || !self::isAllowedRemoteUrl($url)) {
            return $url;
        }

        return rtrim(self::baseUrl(), '/') . '/instagram/media?url=' . rawurlencode($url);
    }

    private static function cacheDir(): string
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'media';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private static function baseUrl(): string
    {
        $configured = trim((string) (Env::get('MEDIA_PROXY_BASE_URL', '') ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $https = $_SERVER['HTTPS'] ?? null;
        $scheme = (!empty($https) && $https !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if ($host !== '') {
            return $scheme . '://' . $host;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function preferVideoPosterImage(array $item): bool
    {
        return (int) (Env::get('MEDIA_PROXY_VIDEO_AS_IMAGE', '0') ?? '0') === 1
            && ($item['media_type'] ?? null) === 'VIDEO';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readMeta(string $metaFile): ?array
    {
        if (!is_file($metaFile)) {
            return null;
        }

        $raw = @file_get_contents($metaFile);
        if ($raw === false) {
            return null;
        }

        $meta = json_decode($raw, true);
        return is_array($meta) ? $meta : null;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private static function isFresh(?array $meta, string $bodyFile, int $ttl): bool
    {
        if (!is_array($meta) || !is_file($bodyFile)) {
            return false;
        }

        $fetchedAt = (int) ($meta['fetched_at'] ?? 0);
        return $fetchedAt > 0 && ($fetchedAt + $ttl) >= time();
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetchRemoteToCache(string $url, string $bodyFile, string $metaFile, int $maxBytes): array
    {
        $tmpFile = $bodyFile . '.tmp';
        @unlink($tmpFile);

        $client = new Client(self::httpOptions());

        try {
            $response = $client->get($url, [
                'http_errors' => false,
                'sink' => $tmpFile,
                'allow_redirects' => true,
                'headers' => [
                    'User-Agent' => 'meta-php-service-media-proxy/1.0',
                ],
            ]);
        } catch (GuzzleException $e) {
            @unlink($tmpFile);
            throw new \RuntimeException('Remote fetch failed: ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            @unlink($tmpFile);
            throw new \RuntimeException('Remote responded with HTTP ' . $status);
        }

        $contentType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? '');
        if (!self::isSupportedContentType($contentType)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Unsupported content type: ' . $contentType);
        }

        $declaredLength = (int) $response->getHeaderLine('Content-Length');
        if ($declaredLength > 0 && $declaredLength > $maxBytes) {
            @unlink($tmpFile);
            throw new \RuntimeException('Remote media exceeds size limit');
        }

        $size = @filesize($tmpFile);
        if ($size === false || $size <= 0) {
            @unlink($tmpFile);
            throw new \RuntimeException('Downloaded media is empty');
        }
        if ($size > $maxBytes) {
            @unlink($tmpFile);
            throw new \RuntimeException('Downloaded media exceeds size limit');
        }

        if (!@rename($tmpFile, $bodyFile)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Failed to move cached media into place');
        }

        $meta = [
            'content_type' => $contentType,
            'content_length' => $size,
            'etag' => '"' . sha1_file($bodyFile) . '"',
            'fetched_at' => time(),
            'source_url' => $url,
        ];
        @file_put_contents($metaFile, json_encode($meta), LOCK_EX);

        return $meta;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function emitCachedFile(string $bodyFile, array $meta, int $ttl): string
    {
        $etag = (string) ($meta['etag'] ?? '');
        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

        if ($etag !== '' && $ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=' . $ttl . ', stale-while-revalidate=3600');
            return '';
        }

        header('Content-Type: ' . ($meta['content_type'] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=' . $ttl . ', stale-while-revalidate=3600');
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT');
        if ($etag !== '') {
            header('ETag: ' . $etag);
        }

        $fetchedAt = (int) ($meta['fetched_at'] ?? 0);
        if ($fetchedAt > 0) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $fetchedAt) . ' GMT');
        }

        $size = @filesize($bodyFile);
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }

        $fp = @fopen($bodyFile, 'rb');
        if ($fp === false) {
            http_response_code(500);
            header('Content-Type: application/json');
            return json_encode(['error' => 'media_proxy_read_failed'], JSON_UNESCAPED_SLASHES) ?: '{"error":"media_proxy_read_failed"}';
        }

        fpassthru($fp);
        fclose($fp);
        return '';
    }

    private static function isAllowedRemoteUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return false;
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) (Env::get('MEDIA_PROXY_ALLOWED_HOSTS', 'cdninstagram.com,fbcdn.net,fbsbx.com,instagram.com') ?? ''))));
        foreach ($allowed as $domain) {
            $domain = strtolower($domain);
            if ($domain === '') {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function httpOptions(): array
    {
        $options = [
            'timeout' => 120.0,
        ];

        $verifyEnv = Env::get('HTTP_VERIFY_SSL', '1');
        $caPath = Env::get('CA_BUNDLE_PATH');
        if ($caPath) {
            $options['verify'] = $caPath;
        } elseif ($verifyEnv === '0') {
            $options['verify'] = false;
        } elseif (Env::isLocal()) {
            $options['verify'] = false;
        }

        return $options;
    }

    private static function isSupportedContentType(string $contentType): bool
    {
        return str_starts_with($contentType, 'image/') || str_starts_with($contentType, 'video/');
    }

    private static function sanitizeForLogs(string $value): string
    {
        $value = preg_replace('/access_token=[^&]+/i', 'access_token=***', $value) ?? $value;
        return mb_substr($value, 0, 500);
    }
}