<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Cache;
use App\Support\Env;
use App\Support\Logger;
use App\Support\SecretStore;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class InstagramService
{
    private const TRANSIENT_GET_MAX_ATTEMPTS = 3;
    private const TRANSIENT_GET_BASE_DELAY_USEC = 500000;

    private Client $http;
    private Cache $cache;

    public function __construct(?Client $client = null, ?Cache $cache = null)
    {
        if ($client) {
            $this->http = $client;
        } else {
            $options = [
                'base_uri' => 'https://graph.facebook.com/',
                'timeout' => 60.0,
            ];
            // TLS/CA verification configuration for local environments
            $verifyEnv = Env::get('HTTP_VERIFY_SSL', '1'); // '1' (default) or '0'
            $caPath = Env::get('CA_BUNDLE_PATH'); // optional absolute path to cacert.pem
            if ($caPath) {
                $options['verify'] = $caPath; // use specific CA bundle
            } elseif ($verifyEnv === '0') {
                $options['verify'] = false; // NOT recommended for production
            } elseif (Env::isLocal()) {
                // Auto-relax verification on localhost only if not explicitly configured
                $options['verify'] = false;
            }
            $this->http = new Client($options);
        }
        $this->cache = $cache ?? new Cache();
    }

    /**
     * Fetch media for a hashtag.
     * @param string $tag The hashtag name without #
     * @param string $type 'recent' or 'top'
     * @param int $limit Max items to return
     * @param string|null $fields Optional Graph API fields to request
     * @return array<int, array<string, mixed>> Normalized items
     * @throws \RuntimeException on configuration or HTTP errors
     */
    public function getHashtagMedia(string $tag, string $type = 'recent', int $limit = 12, ?string $fields = null): array
    {
        $igBusinessId = Env::get('IG_BUSINESS_ACCOUNT_ID');
        $accessToken = $this->resolveAccessToken();
        $apiVersion = Env::get('GRAPH_API_VERSION', 'v24.0');
        $cacheTtl = (int) (Env::get('CACHE_TTL_SECONDS', '86400') ?? '86400'); // default 24 hours

        if (!$igBusinessId || !$accessToken) {
            throw new \RuntimeException('Instagram credentials missing: set IG_BUSINESS_ACCOUNT_ID and IG_ACCESS_TOKEN');
        }

        $tag = strtolower(ltrim($tag, "# "));
        $limit = max(1, min(50, $limit));
        $type = $type === 'top' ? 'top_media' : 'recent_media';

    // Do not request children inline; fetch via separate endpoint when needed
    $fields = $fields ?: 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username';

        $cacheKey = sprintf('ig_tag_%s_%s_%d_%s', $tag, $type, $limit, md5($fields));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $hashtagId = $this->resolveHashtagId($tag, $igBusinessId, $accessToken, $apiVersion);

        $endpoint = sprintf('%s/%s/ig_hashtag_search', $apiVersion, $igBusinessId);
        // Actually, media is fetched from /{ig_hashtag_id}/{recent_media|top_media}
        $mediaEndpoint = sprintf('%s/%s/%s', $apiVersion, $hashtagId, $type);

        try {
            $resp = $this->http->get($mediaEndpoint, [
                'query' => [
                    'user_id' => $igBusinessId,
                    'fields' => $fields,
                    'limit' => $limit,
                    'access_token' => $accessToken,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP error fetching hashtag media: ' . $e->getMessage(), 0);
        }

        $data = json_decode((string) $resp->getBody(), true);
        $items = $data['data'] ?? [];
        $normalized = array_map(fn ($item) => $this->normalizeMedia($item), $items);

        $this->cache->set($cacheKey, $normalized, $cacheTtl);
        return $normalized;
    }

    /**
     * Fetch media posted by the authenticated IG Business/Creator account.
     * @param int $limit 1..50
     * @param string|null $fields Graph API fields
     * @return array<int, array<string, mixed>>
     */
    public function getUserMedia(int $limit = 12, ?string $fields = null): array
    {
        $igBusinessId = Env::get('IG_BUSINESS_ACCOUNT_ID');
        $accessToken = $this->resolveAccessToken();
        $apiVersion = Env::get('GRAPH_API_VERSION', 'v24.0');
        $cacheTtl = (int) (Env::get('CACHE_TTL_SECONDS', '86400') ?? '86400');

        if (!$igBusinessId || !$accessToken) {
            throw new \RuntimeException('Instagram credentials missing: set IG_BUSINESS_ACCOUNT_ID and IG_ACCESS_TOKEN');
        }

        $limit = max(1, min(50, $limit));
    // Do not request children inline; fetch via separate endpoint when needed
    $fields = $fields ?: 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username';

        $cacheKey = sprintf('ig_user_media_%s_%d_%s', $igBusinessId, $limit, md5($fields));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $endpoint = sprintf('%s/%s/media', $apiVersion, $igBusinessId);
        try {
            $resp = $this->http->get($endpoint, [
                'query' => [
                    'fields' => $fields,
                    'limit' => $limit,
                    'access_token' => $accessToken,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP error fetching user media: ' . $e->getMessage(), 0);
        }

        $data = json_decode((string) $resp->getBody(), true);
        $items = $data['data'] ?? [];
        $normalized = array_map(fn ($item) => $this->normalizeMedia($item), $items);
        $this->cache->set($cacheKey, $normalized, $cacheTtl);
        return $normalized;
    }

    /**
     * Fetch media where the IG account is tagged.
     * @param int $limit 1..50
     * @param string|null $fields Graph API fields
     * @return array<int, array<string, mixed>>
     */
    public function getUserTaggedMedia(int $limit = 12, ?string $fields = null): array
    {
        $igBusinessId = Env::get('IG_BUSINESS_ACCOUNT_ID');
        $accessToken = $this->resolveAccessToken();
        $apiVersion = Env::get('GRAPH_API_VERSION', 'v24.0');
        $cacheTtl = (int) (Env::get('CACHE_TTL_SECONDS', '86400') ?? '86400');

        if (!$igBusinessId || !$accessToken) {
            throw new \RuntimeException('Instagram credentials missing: set IG_BUSINESS_ACCOUNT_ID and IG_ACCESS_TOKEN');
        }

        $limit = max(1, min(50, $limit));
    // Do not request children inline; fetch via separate endpoint when needed
    $fields = $fields ?: 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username';

        $cacheKey = sprintf('ig_user_tags_%s_%d_%s', $igBusinessId, $limit, md5($fields));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $endpoint = sprintf('%s/%s/tags', $apiVersion, $igBusinessId);
        try {
            $resp = $this->http->get($endpoint, [
                'query' => [
                    'fields' => $fields,
                    'limit' => $limit,
                    'access_token' => $accessToken,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP error fetching tagged media: ' . $e->getMessage(), 0);
        }

        $data = json_decode((string) $resp->getBody(), true);
        $items = $data['data'] ?? [];
        $normalized = array_map(fn ($item) => $this->normalizeMedia($item), $items);
        $this->cache->set($cacheKey, $normalized, $cacheTtl);
        return $normalized;
    }

    /**
     * Fetch all tagged media via paging and persist to a JSON file.
     * The file structure is { "updated_at": ISO8601, "count": N, "data": [ ...normalized items... ] }.
     * Returns the saved summary (without writing children).
     *
     * @param int $perPage 1..50 page size for Graph API
     * @param int $maxPages Safety cap on number of pages to request
     * @param string|null $outFile Optional absolute path for output JSON file; defaults to var/cache/ig_tagged.json
     * @param string|null $fields Optional comma-separated fields (children not supported inline)
     * @return array{updated_at:string,count:int}
     */
    public function refreshAllTaggedToFile(int $perPage = 3, int $maxPages = 500, ?string $outFile = null, ?string $fields = null): array
    {
        $igBusinessId = Env::get('IG_BUSINESS_ACCOUNT_ID');
        $accessToken = $this->resolveAccessToken();
        $apiVersion = Env::get('GRAPH_API_VERSION', 'v24.0');

        if (!$igBusinessId || !$accessToken) {
            throw new \RuntimeException('Instagram credentials missing: set IG_BUSINESS_ACCOUNT_ID and IG_ACCESS_TOKEN');
        }
        $attemptPerPage = max(1, min(50, $perPage));
        $fields = $fields ?: 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username,children{media_type,media_url,thumbnail_url}';
        $didStripChildren = false;
        $map = [];
        while (true) {
            if (defined('STDERR')) {
                @fwrite(STDERR, sprintf("[tagged-refresh] attempt perPage=%d fields_has_children=%s\n", $attemptPerPage, (is_string($fields) && str_contains($fields, 'children{')) ? 'yes' : 'no'));
            }
            $basePath = sprintf('%s/%s/tags', $apiVersion, $igBusinessId);
            $initialQuery = [
                'fields' => $fields,
                'limit' => $attemptPerPage,
                'access_token' => $accessToken,
            ];
            $nextUrl = $basePath . '?' . http_build_query($initialQuery);
            $page = 0;
            $map = [];

            $reduceSuggested = false;
            do {
                $page++;
                try {
                    // "next" provides an absolute URL; the initial URL is relative to base_uri
                    $resp = $this->getWithTransientRetry($nextUrl, ['http_errors' => false], 'Tagged fetch');
                } catch (GuzzleException $e) {
                    $reduceSuggested = $this->isReduceAmountError($e);
                    if ($reduceSuggested) {
                        Logger::warning(sprintf('Graph reduce-data hint on tagged fetch (perPage=%d). Will retry smaller.', $attemptPerPage));
                        if (defined('STDERR')) {
                            @fwrite(STDERR, sprintf("[tagged-refresh] Graph asked to reduce data (perPage=%d)\n", $attemptPerPage));
                        }
                    } else {
                        Logger::error('Tagged fetch failed: ' . $this->sanitizeForLogs($e->getMessage()));
                        if (defined('STDERR')) {
                            @fwrite(STDERR, "[tagged-refresh] HTTP failed (see app.log for details)\n");
                        }
                    }
                    break;
                }

                $status = $resp->getStatusCode();
                if ($status >= 400) {
                    $body = (string) $resp->getBody();
                    $reduceSuggested = $this->isReduceAmountErrorBody($body);
                    if ($reduceSuggested) {
                        Logger::warning(sprintf('Graph reduce-data hint on tagged fetch (perPage=%d). Will retry smaller.', $attemptPerPage));
                        if (defined('STDERR')) {
                            @fwrite(STDERR, sprintf("[tagged-refresh] Graph asked to reduce data (perPage=%d)\n", $attemptPerPage));
                        }
                    } else {
                        Logger::error('Tagged fetch failed: HTTP ' . $status . ' ' . $this->sanitizeForLogs($body));
                        if (defined('STDERR')) {
                            @fwrite(STDERR, "[tagged-refresh] HTTP failed (see app.log for details)\n");
                        }
                    }
                    break;
                }
                $data = json_decode((string) $resp->getBody(), true) ?: [];
                $items = $data['data'] ?? [];
                foreach ($items as $it) {
                    $n = $this->normalizeMedia($it);
                    $id = (string)($n['id'] ?? '');
                    if ($id !== '') {
                        $map[$id] = $n;
                    }
                }
                $nextUrl = (isset($data['paging']['next']) && is_string($data['paging']['next'])) ? $data['paging']['next'] : null;
            } while ($nextUrl && $page < $maxPages);

            if ($reduceSuggested) {
                if ($attemptPerPage > 1) {
                    // Back off faster than -1 to avoid a long retry ladder.
                    $nextAttempt = (int) floor($attemptPerPage / 2);
                    $attemptPerPage = max(1, min($attemptPerPage - 1, $nextAttempt));
                    if (defined('STDERR')) {
                        @fwrite(STDERR, sprintf("[tagged-refresh] retrying with smaller perPage=%d\n", $attemptPerPage));
                    }
                    continue;
                }

                // If we're already at the smallest page size, the "amount of data" can also be fields expansion.
                if (!$didStripChildren && is_string($fields) && str_contains($fields, 'children{')) {
                    $didStripChildren = true;
                    $fields = $this->stripChildrenField($fields);
                    Logger::warning('Graph reduce-data hint persisted at perPage=1; retrying without children expansion.');
                    if (defined('STDERR')) {
                        @fwrite(STDERR, "[tagged-refresh] retrying without children expansion\n");
                    }
                    continue;
                }

                Logger::error('Graph reduce-data hint persisted down to perPage=1; accepting empty/partial snapshot.');
                if (defined('STDERR')) {
                    @fwrite(STDERR, "[tagged-refresh] reduce-data persisted; giving up and keeping previous snapshot if present\n");
                }
            }
            break; // success or non-retry error
        }

        // Sort newest first
        $all = array_values($map);
        usort($all, function ($a, $b) {
            $tsA = isset($a['timestamp']) ? strtotime((string)$a['timestamp']) : false;
            $tsB = isset($b['timestamp']) ? strtotime((string)$b['timestamp']) : false;
            $ta = ($tsA !== false) ? (int)$tsA : 0;
            $tb = ($tsB !== false) ? (int)$tsB : 0;
            return $tb <=> $ta;
        });

        $count = count($all);
        $outPath = $outFile ?: dirname(__DIR__, 2) . '/var/cache/ig_tagged.json';
        if ($count === 0) {
            Logger::warning('Tagged media snapshot empty; skipping write to preserve previous data.');
            if (is_file($outPath)) {
                $raw = @file_get_contents($outPath);
                $prev = $raw ? (json_decode($raw, true) ?: []) : [];
                return [
                    'updated_at' => $prev['updated_at'] ?? null,
                    'count' => (int)($prev['count'] ?? 0),
                ];
            }
            return ['updated_at' => null, 'count' => 0];
        }

        $payload = [
            'updated_at' => gmdate('c'),
            'count' => $count,
            'data' => $all,
        ];

        @file_put_contents($outPath, json_encode($payload));

        return ['updated_at' => $payload['updated_at'], 'count' => $payload['count']];
    }
    /**
     * Fetch all self (user) media via paging and persist to a JSON file.
     * Structure mirrors tagged snapshot: {updated_at,count,data[]} with normalized items.
     * Supports optional child expansion (if not requesting children inline via fields) similar to tagged.
     *
     * @param int $perPage 1..50 page size
     * @param int $maxPages Safety cap on number of pages to walk
     * @param string|null $outFile Optional absolute output path (default var/cache/ig_user_media.json)
     * @param string|null $fields Optional Graph API fields override; if omitted, includes children inline
     * @param bool $includeChildren If true and children are not requested inline, fetch them per carousel item
     * @param string|null $childrenFields Fields for child requests (when includeChildren=true)
     * @param int|null $maxChildrenRequests Safety cap on number of child requests (default: all carousels)
     * @return array{updated_at:string,count:int}
     */
    public function refreshAllUserMediaToFile(
        int $perPage = 3,
        int $maxPages = 500,
        ?string $outFile = null,
        ?string $fields = null
    ): array {
        $igBusinessId = Env::get('IG_BUSINESS_ACCOUNT_ID');
        $accessToken = $this->resolveAccessToken();
        $apiVersion = Env::get('GRAPH_API_VERSION', 'v24.0');

        if (!$igBusinessId || !$accessToken) {
            throw new \RuntimeException('Instagram credentials missing: set IG_BUSINESS_ACCOUNT_ID and IG_ACCESS_TOKEN');
        }
        $attemptPerPage = max(1, min(50, $perPage));
        $fields = $fields ?: 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username,children{media_type,media_url,thumbnail_url}';

        $map = [];
        while (true) {
            $basePath = sprintf('%s/%s/media', $apiVersion, $igBusinessId);
            $initialQuery = [
                'fields' => $fields,
                'limit' => $attemptPerPage,
                'access_token' => $accessToken,
            ];
            $nextUrl = $basePath . '?' . http_build_query($initialQuery);
            $page = 0;
            $map = [];

            $reduceSuggested = false;
            do {
                $page++;
                try {
                    $resp = $this->getWithTransientRetry($nextUrl, [], 'User media fetch');
                } catch (GuzzleException $e) {
                    $reduceSuggested = $this->isReduceAmountError($e);
                    if ($reduceSuggested) {
                        Logger::warning(sprintf('Graph reduce-data hint on user media fetch (perPage=%d). Will retry smaller.', $attemptPerPage));
                    } else {
                        Logger::error('User media fetch failed: ' . $this->sanitizeForLogs($e->getMessage()));
                    }
                    break;
                }
                $data = json_decode((string)$resp->getBody(), true) ?: [];
                $items = $data['data'] ?? [];
                foreach ($items as $it) {
                    $n = $this->normalizeMedia($it);
                    $id = (string)($n['id'] ?? '');
                    if ($id !== '') {
                        $map[$id] = $n;
                    }
                }
                $nextUrl = (isset($data['paging']['next']) && is_string($data['paging']['next'])) ? $data['paging']['next'] : null;
            } while ($nextUrl && $page < $maxPages);

            if ($reduceSuggested) {
                if ($attemptPerPage > 1) {
                    $attemptPerPage--; // try again with smaller page size
                    continue;
                }
                Logger::error('Graph reduce-data hint persisted down to perPage=0 for user media; accepting empty/partial snapshot.');
            }
            break; // success or non-retry error
        }

        $all = array_values($map);
        usort($all, function ($a, $b) {
            $tsA = isset($a['timestamp']) ? strtotime((string)$a['timestamp']) : false;
            $tsB = isset($b['timestamp']) ? strtotime((string)$b['timestamp']) : false;
            $ta = ($tsA !== false) ? (int)$tsA : 0;
            $tb = ($tsB !== false) ? (int)$tsB : 0;
            return $tb <=> $ta; // newest first
        });

        // Children are requested inline via Graph fields by default (children{...}).

        $count = count($all);
        $outPath = $outFile ?: dirname(__DIR__, 2) . '/var/cache/ig_user_media.json';
        if ($count === 0) {
            Logger::warning('User media snapshot empty; skipping write to preserve previous data.');
            if (is_file($outPath)) {
                $raw = @file_get_contents($outPath);
                $prev = $raw ? (json_decode($raw, true) ?: []) : [];
                return [
                    'updated_at' => $prev['updated_at'] ?? null,
                    'count' => (int)($prev['count'] ?? 0),
                ];
            }
            return ['updated_at' => null, 'count' => 0];
        }

        $payload = [
            'updated_at' => gmdate('c'),
            'count' => $count,
            'data' => $all,
        ];
        @file_put_contents($outPath, json_encode($payload));
        return ['updated_at' => $payload['updated_at'], 'count' => $payload['count']];
    }

    /**
     * Load locally saved self (user) media JSON snapshot.
     * @param string|null $file Optional absolute file path
     * @return array{updated_at: string|null, count: int, data: array<int, array<string,mixed>>}
     */
    public function loadUserMediaFromFile(?string $file = null): array
    {
        $file = $file ?: dirname(__DIR__, 2) . '/var/cache/ig_user_media.json';
        if (!is_file($file)) {
            return ['updated_at' => null, 'count' => 0, 'data' => []];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return ['updated_at' => null, 'count' => 0, 'data' => []];
        }
        $data = json_decode($raw, true) ?: [];
        $list = $data['data'] ?? [];
        return [
            'updated_at' => $data['updated_at'] ?? null,
            'count' => (int)($data['count'] ?? count($list)),
            'data' => is_array($list) ? $list : [],
        ];
    }

    /**
     * Load locally saved tagged media JSON.
     * Returns ['updated_at' => string|null, 'count' => int, 'data' => array]
     * @param string|null $file Optional absolute file path
     */
    public function loadTaggedFromFile(?string $file = null): array
    {
        $file = $file ?: dirname(__DIR__, 2) . '/var/cache/ig_tagged.json';
        if (!is_file($file)) {
            return ['updated_at' => null, 'count' => 0, 'data' => []];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return ['updated_at' => null, 'count' => 0, 'data' => []];
        }
        $data = json_decode($raw, true) ?: [];
        $list = $data['data'] ?? [];
        return [
            'updated_at' => $data['updated_at'] ?? null,
            'count' => (int)($data['count'] ?? count($list)),
            'data' => is_array($list) ? $list : [],
        ];
    }

    /**
     * Detect Graph API error asking to reduce the amount of data requested.
     */
    private function isReduceAmountError(\GuzzleHttp\Exception\GuzzleException $e): bool
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException) {
            $resp = $e->getResponse();
            if ($resp) {
                $body = (string) $resp->getBody();
                return $this->isReduceAmountErrorBody($body);
            }
        }
        return false;
    }

    private function isReduceAmountErrorBody(string $body): bool
    {
        $j = json_decode($body, true);
        $code = (int)($j['error']['code'] ?? 0);
        $message = (string)($j['error']['message'] ?? '');
        return $code === 1 || stripos($message, 'reduce the amount of data') !== false;
    }

    /**
     * Retry idempotent GETs that fail due to transient transport issues.
     *
     * @param array<string,mixed> $options
     */
    private function getWithTransientRetry(string $url, array $options = [], string $operation = 'Graph request'): \Psr\Http\Message\ResponseInterface
    {
        $maxAttempts = self::TRANSIENT_GET_MAX_ATTEMPTS;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->http->get($url, $options);
            } catch (GuzzleException $e) {
                $shouldRetry = $attempt < $maxAttempts && $this->shouldRetryTransientGet($e);
                if (!$shouldRetry) {
                    throw $e;
                }

                Logger::warning(sprintf(
                    '%s transient transport failure on attempt %d/%d: %s',
                    $operation,
                    $attempt,
                    $maxAttempts,
                    $this->sanitizeForLogs($e->getMessage())
                ));
                usleep(self::TRANSIENT_GET_BASE_DELAY_USEC * $attempt);
            }
        }

        throw new \RuntimeException(sprintf('%s retry loop exited unexpectedly.', $operation));
    }

    private function shouldRetryTransientGet(GuzzleException $e): bool
    {
        if ($e instanceof ConnectException) {
            return true;
        }

        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return false;
        }

        $message = strtolower($e->getMessage());
        $transientNeedles = [
            'curl error 18',
            'curl error 28',
            'curl error 35',
            'curl error 52',
            'curl error 56',
            'connection reset by peer',
            'empty reply from server',
            'operation timed out',
            'recv failure',
            'ssl_connect',
        ];

        foreach ($transientNeedles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeForLogs(string $message): string
    {
        $sanitized = preg_replace('/(access_token=)([^&\s]+)/i', '$1REDACTED', $message);
        return is_string($sanitized) ? $sanitized : $message;
    }

    /**
     * Remove a children{...} expansion from a Graph API fields list.
     */
    private function stripChildrenField(string $fields): string
    {
        $stripped = preg_replace('/,?\s*children\{[^}]*\}\s*/', '', $fields);
        if (!is_string($stripped) || trim($stripped) === '') {
            return $fields;
        }
        $stripped = preg_replace('/\s*,\s*/', ',', trim($stripped));
        $stripped = trim((string)$stripped, ',');
        return $stripped !== '' ? $stripped : $fields;
    }

    /**
     * Fetch merged list of self media and tagged media, newest first.
     * Uses underlying caches of self and tagged endpoints, then caches the merged result.
     * @param int $limit Total items to return
     * @param string|null $fields Graph API fields
     * @return array<int, array<string,mixed>>
     */
    public function getMergedSelfAndTagged(int $limit = 12, ?string $fields = null): array
    {
        $igBusinessId = Env::get('IG_BUSINESS_ACCOUNT_ID');
        $cacheTtl = (int) (Env::get('CACHE_TTL_SECONDS', '86400') ?? '86400'); // default 24 hours
        $limit = max(1, min(50, $limit));

        // Do not request children inline; fetch via separate endpoint when needed
        $fields = $fields ?: 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username';
        $cacheKey = sprintf('ig_user_merged_%s_%d_%s', (string)$igBusinessId, $limit, md5($fields));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        // Fetch from underlying endpoints (each uses its own cache)
        $self = $this->getUserMedia($limit, $fields);
        $tags = $this->getUserTaggedMedia($limit, $fields);

        // Merge and sort by timestamp desc; de-duplicate by id
        $all = array_merge($self, $tags);
        usort($all, function ($a, $b) {
            $tsA = isset($a['timestamp']) ? strtotime((string)$a['timestamp']) : false;
            $tsB = isset($b['timestamp']) ? strtotime((string)$b['timestamp']) : false;
            $ta = ($tsA !== false) ? (int)$tsA : 0;
            $tb = ($tsB !== false) ? (int)$tsB : 0;
            return $tb <=> $ta; // newest first
        });
        $seen = [];
        $merged = [];
        foreach ($all as $item) {
            $id = (string)($item['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $merged[] = $item;
            if (count($merged) >= $limit) break;
        }

        $this->cache->set($cacheKey, $merged, $cacheTtl);
        return $merged;
    }

    private function resolveHashtagId(string $tag, string $igBusinessId, string $token, string $version): string
    {
        try {
            $resp = $this->http->get(sprintf('%s/ig_hashtag_search', $version), [
                'query' => [
                    'user_id' => $igBusinessId,
                    'q' => $tag,
                    'access_token' => $token,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP error searching hashtag: ' . $e->getMessage(), 0);
        }
        $data = json_decode((string) $resp->getBody(), true);
        $id = $data['data'][0]['id'] ?? null;
        if (!$id) {
            throw new \RuntimeException('Hashtag not found: #' . $tag);
        }
        return (string) $id;
    }

    private function normalizeMedia(array $item): array
    {
        $mediaType = $item['media_type'] ?? null;
        $mediaUrl = $item['media_url'] ?? null;
        $thumbnailUrl = $item['thumbnail_url'] ?? null;

        return [
            'id' => $item['id'] ?? null,
            'username' => $item['username'] ?? null,
            'caption' => $item['caption'] ?? null,
            'media_type' => $mediaType,
            'media_url' => $mediaUrl ?? $thumbnailUrl,
            'thumbnail_url' => $thumbnailUrl,
            'video_url' => $mediaType === 'VIDEO' ? $mediaUrl : null,
            'permalink' => $item['permalink'] ?? null,
            'timestamp' => $item['timestamp'] ?? null,
            'children' => $item['children']['data'] ?? [],
        ];
    }

    /**
     * Fetch children of a carousel media item by media ID.
     * Note: Only applicable when the media_id refers to a CAROUSEL_ALBUM parent item.
     * @param string $mediaId Parent media id
     * @param string|null $fields Optional fields override
     * @return array<int, array<string,mixed>> Normalized child items
     */
    public function getMediaChildren(string $mediaId, ?string $fields = null): array
    {
        $accessToken = $this->resolveAccessToken();
        $apiVersion = Env::get('GRAPH_API_VERSION', 'v24.0');

        if (!$mediaId) {
            throw new \InvalidArgumentException('mediaId is required');
        }
        if (!$accessToken) {
            throw new \RuntimeException('Instagram access token missing');
        }

        $fields = $fields ?: 'id,media_type,media_url,thumbnail_url,permalink,username,timestamp';

        $endpoint = sprintf('%s/%s/children', $apiVersion, $mediaId);
        try {
            $resp = $this->http->get($endpoint, [
                'query' => [
                    'fields' => $fields,
                    'access_token' => $accessToken,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('HTTP error fetching media children: ' . $e->getMessage(), 0);
        }

        $data = json_decode((string) $resp->getBody(), true);
        $items = $data['data'] ?? [];

        // Normalize children similarly to parent media shape, without nested children
        return array_map(function ($c) {
            $mediaType = $c['media_type'] ?? null;
            $mediaUrl = $c['media_url'] ?? null;
            $thumbnailUrl = $c['thumbnail_url'] ?? null;

            return [
                'id' => $c['id'] ?? null,
                'username' => $c['username'] ?? null,
                'caption' => $c['caption'] ?? null,
                'media_type' => $mediaType,
                'media_url' => $mediaUrl ?? $thumbnailUrl,
                'thumbnail_url' => $thumbnailUrl,
                'video_url' => $mediaType === 'VIDEO' ? $mediaUrl : null,
                'permalink' => $c['permalink'] ?? null,
                'timestamp' => $c['timestamp'] ?? null,
                'children' => [],
            ];
        }, $items);
    }

    private function resolveAccessToken(): ?string
    {
        $storage = Env::get('IG_TOKEN_STORAGE');
        if ($storage) {
            $token = SecretStore::read($storage);
            if ($token) return $token;
        }
        return Env::get('IG_ACCESS_TOKEN');
    }
}
