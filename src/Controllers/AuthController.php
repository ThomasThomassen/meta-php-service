<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\TokenService;
use App\Support\Env;
use App\Support\Response;

class AuthController
{
    private function isAllowed(): bool
    {
        $whitelist = trim((string) (Env::get('WHITELISTED_IPS', '') ?? ''));
        $ips = array_filter(array_map('trim', explode(',', $whitelist)));
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $adminToken = Env::get('ADMIN_TOKEN');
        $provided = $_GET['admin_token'] ?? '';
        if ($adminToken && hash_equals($adminToken, (string)$provided)) {
            return true;
        }
        if ($remote && in_array($remote, $ips, true)) {
            return true;
        }
        return false;
    }

    public function debug(): string
    {
        if (!$this->isAllowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $provided = $_GET['token'] ?? null;
        $token = $provided ?: (Env::get('IG_ACCESS_TOKEN') ?? '');
        // If IG_TOKEN_STORAGE is configured, prefer it
        $storage = Env::get('IG_TOKEN_STORAGE');
        if ($storage && is_readable($storage)) {
            $fromFile = trim((string) @file_get_contents($storage));
            if ($fromFile !== '') $token = $fromFile;
        }
        if ($token === '') {
            return Response::json(['error' => 'missing_token'], 400);
        }
        try {
            $svc = new TokenService();
            $data = $svc->debugToken($token);
            return Response::json(['data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'debug_failed', 'message' => $e->getMessage()], 502);
        }
    }

    public function refresh(): string
    {
        if (!$this->isAllowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $provided = $_GET['token'] ?? null;
        $token = $provided ?: (Env::get('IG_ACCESS_TOKEN') ?? '');
        // If IG_TOKEN_STORAGE is configured, prefer it
        $storage = Env::get('IG_TOKEN_STORAGE');
        if ($storage && is_readable($storage)) {
            $fromFile = trim((string) @file_get_contents($storage));
            if ($fromFile !== '') $token = $fromFile;
        }
        if ($token === '') {
            return Response::json(['error' => 'missing_token'], 400);
        }
        try {
            $svc = new TokenService();
            $data = $svc->refreshLongLived($token);
            return Response::json(['refreshed' => isset($data['access_token']), 'data' => $data]);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'refresh_failed', 'message' => $e->getMessage()], 502);
        }
    }

    public function autoRefresh(): string
    {
        if (!$this->isAllowed()) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $thresholdDays = (int) (Env::get('REFRESH_THRESHOLD_DAYS', '30') ?? '30');
        $provided = $_GET['token'] ?? null; // optional override
        $token = $provided ?: (Env::get('IG_ACCESS_TOKEN') ?? '');
        // If IG_TOKEN_STORAGE is configured, prefer it
        $storage = Env::get('IG_TOKEN_STORAGE');
        if ($storage && is_readable($storage)) {
            $fromFile = trim((string) @file_get_contents($storage));
            if ($fromFile !== '') $token = $fromFile;
        }
        if ($token === '') {
            return Response::json(['error' => 'missing_token'], 400);
        }
        try {
            $svc = new TokenService();
            $info = $svc->debugToken($token);
            $now = time();
            $expiresAt = (int) ($info['expires_at'] ?? 0);
            $expiresIn = (int) ($info['expires_in'] ?? max(0, $expiresAt - $now));
            $shouldRefresh = false;
            if ($expiresAt > 0) {
                $shouldRefresh = ($expiresAt - $now) <= ($thresholdDays * 86400);
            } else {
                // Fallback on expires_in if expires_at absent
                $shouldRefresh = $expiresIn <= ($thresholdDays * 86400);
            }
            $result = [
                'expires_at' => $expiresAt ?: null,
                'expires_in' => $expiresIn ?: null,
                'threshold_days' => $thresholdDays,
                'should_refresh' => $shouldRefresh,
            ];
            if ($shouldRefresh) {
                $res = $svc->refreshLongLived($token);
                $result['refreshed'] = isset($res['access_token']);
                $result['refresh_response'] = $res;
            }
            return Response::json($result);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'auto_refresh_failed', 'message' => $e->getMessage()], 502);
        }
    }
}