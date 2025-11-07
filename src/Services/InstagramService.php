<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Cache;
use App\Support\Env;
use App\Support\SecretStore;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class InstagramService
{
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
        $perPage = max(1, min(50, $perPage));
        $fields = $fields ?: 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username,children{media_type,media_url,thumbnail_url}';

        $basePath = sprintf('%s/%s/tags', $apiVersion, $igBusinessId);
        $initialQuery = [
            'fields' => $fields,
            'limit' => $perPage,
            'access_token' => $accessToken,
        ];
        $nextUrl = $basePath . '?' . http_build_query($initialQuery);
        $page = 0;
        $map = [];

        do {
            $page++;
            try {
                // "next" provides an absolute URL; the initial URL is relative to base_uri
                $resp = $this->http->get($nextUrl);
            } catch (GuzzleException $e) {
                // Stop on error; return what we have
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

        // Sort newest first
        $all = array_values($map);
        usort($all, function ($a, $b) {
            $ta = isset($a['timestamp']) ? strtotime((string)$a['timestamp']) ?: 0 : 0;
            $tb = isset($b['timestamp']) ? strtotime((string)$b['timestamp']) ?: 0 : 0;
            return $tb <=> $ta;
        });

        $payload = [
            'updated_at' => gmdate('c'),
            'count' => count($all),
            'data' => $all,
        ];

        $outFile = $outFile ?: dirname(__DIR__, 2) . '/var/cache/ig_tagged.json';
        @file_put_contents($outFile, json_encode($payload));

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
        $perPage = max(1, min(50, $perPage));
        $fields = $fields ?: 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username,children{media_type,media_url,thumbnail_url}';

        $basePath = sprintf('%s/%s/media', $apiVersion, $igBusinessId);
        $initialQuery = [
            'fields' => $fields,
            'limit' => $perPage,
            'access_token' => $accessToken,
        ];
        $nextUrl = $basePath . '?' . http_build_query($initialQuery);
        $page = 0;
        $map = [];

        do {
            $page++;
            try {
                $resp = $this->http->get($nextUrl);
            } catch (GuzzleException $e) {
                break; // stop on error
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

        $all = array_values($map);
        usort($all, function ($a, $b) {
            $ta = isset($a['timestamp']) ? strtotime((string)$a['timestamp']) ?: 0 : 0;
            $tb = isset($b['timestamp']) ? strtotime((string)$b['timestamp']) ?: 0 : 0;
            return $tb <=> $ta; // newest first
        });

        // Children are requested inline via Graph fields by default (children{...}).

        $payload = [
            'updated_at' => gmdate('c'),
            'count' => count($all),
            'data' => $all,
        ];
        $outFile = $outFile ?: dirname(__DIR__, 2) . '/var/cache/ig_user_media.json';
        @file_put_contents($outFile, json_encode($payload));
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
            $ta = isset($a['timestamp']) ? strtotime((string)$a['timestamp']) ?: 0 : 0;
            $tb = isset($b['timestamp']) ? strtotime((string)$b['timestamp']) ?: 0 : 0;
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
        return [
            'id' => $item['id'] ?? null,
            'username' => $item['username'] ?? null,
            'caption' => $item['caption'] ?? null,
            'media_type' => $item['media_type'] ?? null,
            'media_url' => $item['media_url'] ?? ($item['thumbnail_url'] ?? null),
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
            return [
                'id' => $c['id'] ?? null,
                'username' => $c['username'] ?? null,
                'caption' => $c['caption'] ?? null,
                'media_type' => $c['media_type'] ?? null,
                'media_url' => $c['media_url'] ?? ($c['thumbnail_url'] ?? null),
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
