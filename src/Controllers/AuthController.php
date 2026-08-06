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
        return $this->cronOnlyResponse('HTTP token debugging is disabled. Use the cron-driven token maintenance script instead.');
    }

    public function refresh(): string
    {
        return $this->cronOnlyResponse('HTTP token refresh is disabled. Use scripts/refresh_token.php from cron instead.');
    }

    public function autoRefresh(): string
    {
        return $this->cronOnlyResponse('HTTP token auto-refresh is disabled. Use scripts/refresh_token.php from cron instead.');
    }

    private function cronOnlyResponse(string $message): string
    {
        return Response::json([
            'error' => 'cron_only_mode',
            'message' => $message,
        ], 409);
    }
}