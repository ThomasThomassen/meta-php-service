<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\InstagramService;
use App\Support\Response;

class InstagramController
{
    public function getHashtagMedia(): string
    {
        $tag = trim((string) ($_GET['tag'] ?? ''));
        $limit = (int) ($_GET['limit'] ?? 12);
        $type = $_GET['type'] ?? 'recent'; // 'recent' or 'top'
        $fields = $_GET['fields'] ?? null; // optional comma-separated fields override

        if ($tag === '') {
            return Response::json(['error' => 'missing_tag', 'message' => 'Query param "tag" is required.'], 400);
        }
        if (!in_array($type, ['recent', 'top'], true)) {
            return Response::json(['error' => 'invalid_type', 'message' => 'Type must be recent or top.'], 400);
        }

        $service = new InstagramService();
        try {
            $data = $service->getHashtagMedia($tag, $type, $limit, $fields);
            return Response::json(['tag' => $tag, 'type' => $type, 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error'], 502);
        }
    }

    private function isAllowed(): bool
    {
        $whitelist = trim((string) (\App\Support\Env::get('WHITELISTED_IPS', '') ?? ''));
        $ips = array_filter(array_map('trim', explode(',', $whitelist)));
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $adminToken = \App\Support\Env::get('ADMIN_TOKEN');
        $provided = $_GET['admin_token'] ?? '';
        if ($adminToken && hash_equals($adminToken, (string)$provided)) {
            return true;
        }
        if ($remote && in_array($remote, $ips, true)) {
            return true;
        }
        return false;
    }

    public function getSelfMedia(): string
    {
        $limit = (int) ($_GET['limit'] ?? 12);
        $fields = $_GET['fields'] ?? null;
        $service = new InstagramService();
        try {
            $data = $service->getUserMedia($limit, $fields);
            return Response::json(['scope' => 'self', 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error'], 502);
        }
    }

    public function getTaggedMedia(): string
    {
        $limit = (int) ($_GET['limit'] ?? 12);
        $fields = $_GET['fields'] ?? null;
        $service = new InstagramService();
        try {
            $data = $service->getUserTaggedMedia($limit, $fields);
            return Response::json(['scope' => 'tags', 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error', 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Refresh and persist all self (user) media posts to a local JSON file. Admin-only.
     */
    public function refreshAllUserMedia(): string
    {
        if (!$this->isAllowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $perPage = (int) ($_GET['per_page'] ?? 3);
        $maxPages = (int) ($_GET['max_pages'] ?? 500);
        $fields = $_GET['fields'] ?? null;
        $service = new InstagramService();
        try {
            $summary = $service->refreshAllUserMediaToFile($perPage, $maxPages, null, $fields);
            return Response::json(['refreshed' => true] + $summary);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'refresh_failed', 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Kick off a background refresh of all tagged posts. Admin-only.
     * Spawns the CLI script and returns immediately.
     */
    public function refreshAllTaggedAsync(): string
    {
        if (!$this->isAllowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $perPage = (int) ($_GET['per_page'] ?? 3);
        $maxPages = (int) ($_GET['max_pages'] ?? 500);
        $fields = $_GET['fields'] ?? null;

        $root = dirname(__DIR__, 2);
        $php = PHP_BINARY ?: 'php';
        $script = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'refresh_tagged.php';
        if (!is_file($script)) {
            return Response::json(['error' => 'missing_script'], 500);
        }

        $args = ["--per-page={$perPage}", "--max-pages={$maxPages}"];
        if (is_string($fields) && $fields !== '') {
            $args[] = '--fields=' . $fields;
        }
        $logDir = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
        $log = $logDir . DIRECTORY_SEPARATOR . 'refresh_tagged.log';

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $cmd = '';
        if ($isWindows) {
            // start /B to background; no PID capture
            $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' >> ' . escapeshellarg($log) . ' 2>&1';
            pclose(popen('cmd /c ' . $cmd, 'r'));
            return Response::json(['accepted' => true, 'method' => 'background_windows']);
        } else {
            // nohup + background &
            $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
            $pid = @shell_exec($cmd);
            $pid = $pid ? trim($pid) : null;
            return Response::json(['accepted' => true, 'method' => 'background_unix', 'pid' => $pid]);
        }
    }

    /**
     * Kick off a background refresh of all self media. Admin-only.
     */
    public function refreshAllUserMediaAsync(): string
    {
        if (!$this->isAllowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $perPage = (int) ($_GET['per_page'] ?? 3);
        $maxPages = (int) ($_GET['max_pages'] ?? 500);
        $fields = $_GET['fields'] ?? null;

        $root = dirname(__DIR__, 2);
        $php = PHP_BINARY ?: 'php';
        $script = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'refresh_user_media.php';
        if (!is_file($script)) {
            return Response::json(['error' => 'missing_script'], 500);
        }

        $args = ["--per-page={$perPage}", "--max-pages={$maxPages}"];
    if (is_string($fields) && $fields !== '') $args[] = '--fields=' . $fields;

        $logDir = $root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
        $log = $logDir . DIRECTORY_SEPARATOR . 'refresh_user_media.log';

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' >> ' . escapeshellarg($log) . ' 2>&1';
            pclose(popen('cmd /c ' . $cmd, 'r'));
            return Response::json(['accepted' => true, 'method' => 'background_windows']);
        } else {
            $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' >> ' . escapeshellarg($log) . ' 2>&1 & echo $!';
            $pid = @shell_exec($cmd);
            $pid = $pid ? trim($pid) : null;
            return Response::json(['accepted' => true, 'method' => 'background_unix', 'pid' => $pid]);
        }
    }

    /**
     * Serve locally saved tagged posts with filtering.
     * Supports: limit, offset, ids/mediaid (comma or repeated), username, since, until, shortcode/permalink_id.
     */
    public function getLocalTagged(): string
    {
        $service = new InstagramService();
        $store = $service->loadTaggedFromFile();
        $items = $store['data'] ?? [];

        // Parse filters
        // limit: if not provided, fetch all (no limit)
        $limitParam = $_GET['limit'] ?? null;
        $limit = null;
        if ($limitParam !== null && $limitParam !== '') {
            $limit = max(1, min(1000, (int)$limitParam));
        }
        // offset defaults to 0 when not provided
        $offsetParam = $_GET['offset'] ?? null;
        $offset = 0;
        if ($offsetParam !== null && $offsetParam !== '') {
            $offset = max(0, (int)$offsetParam);
        }

        // ids/mediaid
        $idParam = $_GET['ids'] ?? ($_GET['id'] ?? ($_GET['mediaid'] ?? ''));
        $ids = [];
        if ($idParam !== '') {
            if (is_array($idParam)) {
                $ids = array_values(array_filter(array_map(fn($v) => trim((string)$v), $idParam), fn($v) => $v !== ''));
            } else {
                $ids = array_values(array_filter(array_map('trim', explode(',', (string)$idParam)), fn($v) => $v !== ''));
            }
            $ids = array_values(array_unique($ids));
        }

        // username (supports comma-separated list)
        $userParam = $_GET['username'] ?? '';
        $usernames = [];
        if ($userParam !== '') {
            if (is_array($userParam)) {
                $usernames = array_values(array_filter(array_map('trim', $userParam), fn($v) => $v !== ''));
            } else {
                $usernames = array_values(array_filter(array_map('trim', explode(',', (string)$userParam)), fn($v) => $v !== ''));
            }
            $usernames = array_values(array_unique($usernames));
        }

        // time filters
        $sinceTs = $_GET['since'] ?? null; $since = null;
        if ($sinceTs !== null && $sinceTs !== '') { $t = strtotime((string)$sinceTs); if ($t !== false) $since = $t; }
        $untilTs = $_GET['until'] ?? null; $until = null;
        if ($untilTs !== null && $untilTs !== '') { $t = strtotime((string)$untilTs); if ($t !== false) $until = $t; }

    // shortcode/permalink_id (supports comma-separated or repeated)
    // Enhancement: allow selecting a child via CODE!N (1-based): 1 => first child, 2 => second child, etc.
        $shortParam = $_GET['shortcode'] ?? ($_GET['permalink_id'] ?? '');
        $shortcodes = [];
        $shortcodeSelections = [];
        if ($shortParam !== '') {
            $raws = [];
            if (is_array($shortParam)) {
                $raws = array_values(array_filter(array_map(fn($v) => strtolower(trim((string)$v)), $shortParam), fn($v) => $v !== ''));
            } else {
                $raws = array_values(array_filter(array_map(fn($v) => strtolower(trim($v)), explode(',', (string)$shortParam)), fn($v) => $v !== ''));
            }
            foreach ($raws as $r) {
                $base = $r; $sel = null;
                if (strpos($r, '!') !== false) {
                    [$b, $s] = explode('!', $r, 2);
                    $b = trim($b);
                    $s = trim($s);
                    if ($b !== '' && $s !== '' && ctype_digit($s)) {
                        $base = $b;
                        $sel = (int)$s; // 1-based child selector (1 => children[0])
                        $shortcodeSelections[$base] = $sel;
                    } else {
                        $base = $b !== '' ? $b : $base;
                    }
                }
                if ($base !== '') {
                    $shortcodes[] = $base;
                }
            }
            $shortcodes = array_values(array_unique($shortcodes));
        }

        // Apply filters
        $filtered = array_values(array_filter($items, function ($it) use ($ids, $usernames, $since, $until, $shortcodes) {
            if (!empty($ids) && !in_array((string)($it['id'] ?? ''), $ids, true)) return false;
            if (!empty($usernames) && !in_array((string)($it['username'] ?? ''), $usernames, true)) return false;
            if ($since !== null || $until !== null) {
                $ts = isset($it['timestamp']) ? strtotime((string)$it['timestamp']) ?: 0 : 0;
                if ($since !== null && $ts < $since) return false;
                if ($until !== null && $ts > $until) return false;
            }
            if (!empty($shortcodes)) {
                $plink = (string)($it['permalink'] ?? '');
                if ($plink === '') return false;
                // Extract shortcode from permalink: support /p/{code}/ and /reel/{code}/
                $code = null;
                if (preg_match('~/(?:p|reel)/([^/]+)/?~i', $plink, $m)) {
                    $code = strtolower($m[1]);
                }
                if ($code !== null) {
                    if (!in_array($code, $shortcodes, true)) return false;
                } else {
                    // Fallback: require any provided shortcode to appear in permalink
                    $ok = false;
                    foreach ($shortcodes as $sc) { if (stripos($plink, $sc) !== false) { $ok = true; break; } }
                    if (!$ok) return false;
                }
            }
            return true;
        }));

        // Sort newest first
        usort($filtered, function ($a, $b) {
            $ta = isset($a['timestamp']) ? strtotime((string)$a['timestamp']) ?: 0 : 0;
            $tb = isset($b['timestamp']) ? strtotime((string)$b['timestamp']) ?: 0 : 0;
            return $tb <=> $ta;
        });

        // Apply offset and limit (if limit is null, return all from offset)
        if ($limit === null) {
            $slice = array_slice($filtered, $offset);
        } else {
            $slice = array_slice($filtered, $offset, $limit);
        }

        // If a shortcode selection (CODE!N) was provided, and the item is a carousel with children,
        // override media_url and media_type from children[N-1] and add ?img_index=N (N as provided) to the permalink.
        if (!empty($shortcodeSelections)) {
            foreach ($slice as &$it) {
                $plink = (string)($it['permalink'] ?? '');
                if ($plink === '') continue;
                $code = null;
                if (preg_match('~/(?:p|reel)/([^/]+)/?~i', $plink, $m)) {
                    $code = strtolower($m[1]);
                }
                if ($code === null) continue;
                if (!array_key_exists($code, $shortcodeSelections)) continue;
                $sel = (int)$shortcodeSelections[$code];
                if (($it['media_type'] ?? null) !== 'CAROUSEL_ALBUM') continue;
                $children = isset($it['children']) && is_array($it['children']) ? $it['children'] : [];
                // Selection semantics: 1 => children[0]
                if ($sel < 1) continue;
                $idx = $sel - 1;
                if ($idx < 0 || $idx >= count($children)) continue;
                $child = $children[$idx];
                $childUrl = (string)($child['media_url'] ?? '');
                if ($childUrl !== '') {
                    $it['media_url'] = $childUrl;
                }
                if (isset($child['media_type'])) {
                    $it['media_type'] = $child['media_type'];
                }
                // Append img_index param to permalink
                if ($plink !== '') {
                    if (strpos($plink, '?') === false) {
                        $it['permalink'] = $plink . '?img_index=' . $sel;
                    } else {
                        // avoid duplicating img_index
                        if (stripos($plink, 'img_index=') === false) {
                            $it['permalink'] = $plink . '&img_index=' . $sel;
                        } else {
                            $it['permalink'] = $plink; // leave as is
                        }
                    }
                }
            }
            unset($it);
        }

        return Response::json([
            'source' => 'local',
            'updated_at' => $store['updated_at'] ?? null,
            'requested' => [ 'limit' => $limit, 'offset' => $offset ],
            'returned' => count($slice),
            'total' => count($filtered),
            'data' => $slice,
        ]);
    }

    /**
     * Serve locally saved self (user) media with the same filtering & shortcode child selection logic.
     */
    public function getLocalUserMedia(): string
    {
        $service = new InstagramService();
        $store = $service->loadUserMediaFromFile();
        $items = $store['data'] ?? [];

        $limitParam = $_GET['limit'] ?? null;
        $limit = null;
        if ($limitParam !== null && $limitParam !== '') {
            $limit = max(1, min(1000, (int)$limitParam));
        }
        $offsetParam = $_GET['offset'] ?? null;
        $offset = 0;
        if ($offsetParam !== null && $offsetParam !== '') {
            $offset = max(0, (int)$offsetParam);
        }

        $idParam = $_GET['ids'] ?? ($_GET['id'] ?? ($_GET['mediaid'] ?? ''));
        $ids = [];
        if ($idParam !== '') {
            if (is_array($idParam)) {
                $ids = array_values(array_filter(array_map(fn($v) => trim((string)$v), $idParam), fn($v) => $v !== ''));
            } else {
                $ids = array_values(array_filter(array_map('trim', explode(',', (string)$idParam)), fn($v) => $v !== ''));
            }
            $ids = array_values(array_unique($ids));
        }

        $userParam = $_GET['username'] ?? '';
        $usernames = [];
        if ($userParam !== '') {
            if (is_array($userParam)) {
                $usernames = array_values(array_filter(array_map('trim', $userParam), fn($v) => $v !== ''));
            } else {
                $usernames = array_values(array_filter(array_map('trim', explode(',', (string)$userParam)), fn($v) => $v !== ''));
            }
            $usernames = array_values(array_unique($usernames));
        }

        $sinceTs = $_GET['since'] ?? null; $since = null;
        if ($sinceTs !== null && $sinceTs !== '') { $t = strtotime((string)$sinceTs); if ($t !== false) $since = $t; }
        $untilTs = $_GET['until'] ?? null; $until = null;
        if ($untilTs !== null && $untilTs !== '') { $t = strtotime((string)$untilTs); if ($t !== false) $until = $t; }

        $shortParam = $_GET['shortcode'] ?? ($_GET['permalink_id'] ?? '');
        $shortcodes = [];
        $shortcodeSelections = [];
        if ($shortParam !== '') {
            $raws = [];
            if (is_array($shortParam)) {
                $raws = array_values(array_filter(array_map(fn($v) => strtolower(trim((string)$v)), $shortParam), fn($v) => $v !== ''));
            } else {
                $raws = array_values(array_filter(array_map(fn($v) => strtolower(trim($v)), explode(',', (string)$shortParam)), fn($v) => $v !== ''));
            }
            foreach ($raws as $r) {
                $base = $r; $sel = null;
                if (strpos($r, '!') !== false) {
                    [$b, $s] = explode('!', $r, 2);
                    $b = trim($b);
                    $s = trim($s);
                    if ($b !== '' && $s !== '' && ctype_digit($s)) {
                        $base = $b;
                        $sel = (int)$s; // 1-based child selector
                        $shortcodeSelections[$base] = $sel;
                    } else {
                        $base = $b !== '' ? $b : $base;
                    }
                }
                if ($base !== '') $shortcodes[] = $base;
            }
            $shortcodes = array_values(array_unique($shortcodes));
        }

        $filtered = array_values(array_filter($items, function ($it) use ($ids, $usernames, $since, $until, $shortcodes) {
            if (!empty($ids) && !in_array((string)($it['id'] ?? ''), $ids, true)) return false;
            if (!empty($usernames) && !in_array((string)($it['username'] ?? ''), $usernames, true)) return false;
            if ($since !== null || $until !== null) {
                $ts = isset($it['timestamp']) ? strtotime((string)$it['timestamp']) ?: 0 : 0;
                if ($since !== null && $ts < $since) return false;
                if ($until !== null && $ts > $until) return false;
            }
            if (!empty($shortcodes)) {
                $plink = (string)($it['permalink'] ?? '');
                if ($plink === '') return false;
                $code = null;
                if (preg_match('~/(?:p|reel)/([^/]+)/?~i', $plink, $m)) {
                    $code = strtolower($m[1]);
                }
                if ($code !== null) {
                    if (!in_array($code, $shortcodes, true)) return false;
                } else {
                    $ok = false;
                    foreach ($shortcodes as $sc) { if (stripos($plink, $sc) !== false) { $ok = true; break; } }
                    if (!$ok) return false;
                }
            }
            return true;
        }));

        usort($filtered, function ($a, $b) {
            $ta = isset($a['timestamp']) ? strtotime((string)$a['timestamp']) ?: 0 : 0;
            $tb = isset($b['timestamp']) ? strtotime((string)$b['timestamp']) ?: 0 : 0;
            return $tb <=> $ta;
        });

        if ($limit === null) {
            $slice = array_slice($filtered, $offset);
        } else {
            $slice = array_slice($filtered, $offset, $limit);
        }

        if (!empty($shortcodeSelections)) {
            foreach ($slice as &$it) {
                $plink = (string)($it['permalink'] ?? '');
                if ($plink === '') continue;
                $code = null;
                if (preg_match('~/(?:p|reel)/([^/]+)/?~i', $plink, $m)) $code = strtolower($m[1]);
                if ($code === null) continue;
                if (!array_key_exists($code, $shortcodeSelections)) continue;
                $sel = (int)$shortcodeSelections[$code];
                if (($it['media_type'] ?? null) !== 'CAROUSEL_ALBUM') continue;
                $children = isset($it['children']) && is_array($it['children']) ? $it['children'] : [];
                if ($sel < 1) continue; $idx = $sel - 1;
                if ($idx < 0 || $idx >= count($children)) continue;
                $child = $children[$idx];
                $childUrl = (string)($child['media_url'] ?? '');
                if ($childUrl !== '') $it['media_url'] = $childUrl;
                if (isset($child['media_type'])) $it['media_type'] = $child['media_type'];
                if ($plink !== '') {
                    if (strpos($plink, '?') === false) {
                        $it['permalink'] = $plink . '?img_index=' . $sel;
                    } else if (stripos($plink, 'img_index=') === false) {
                        $it['permalink'] = $plink . '&img_index=' . $sel;
                    }
                }
            }
            unset($it);
        }

        return Response::json([
            'source' => 'local',
            'updated_at' => $store['updated_at'] ?? null,
            'requested' => ['limit' => $limit, 'offset' => $offset],
            'returned' => count($slice),
            'total' => count($filtered),
            'data' => $slice,
        ]);
    }

    public function getMergedMedia(): string
    {
        $limit = (int) ($_GET['limit'] ?? 12);
        $fields = $_GET['fields'] ?? null;
        $service = new InstagramService();
        try {
            $data = $service->getMergedSelfAndTagged($limit, $fields);
            return Response::json(['scope' => 'merged', 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error'], 502);
        }
    }

    public function getChildrenByMediaId(): string
    {
        $mediaId = trim((string)($_GET['mediaid'] ?? ''));
        $fields = $_GET['fields'] ?? null;
        if ($mediaId === '') {
            return Response::json(['error' => 'missing_mediaid', 'message' => 'Query param "mediaid" is required.'], 400);
        }
        $service = new InstagramService();
        try {
            $data = $service->getMediaChildren($mediaId, $fields);
            return Response::json(['scope' => 'children', 'media_id' => $mediaId, 'count' => count($data), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'instagram_error', 'message' => $e->getMessage()], 502);
        }
    }
}
