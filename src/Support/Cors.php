<?php
declare(strict_types=1);

namespace App\Support;

class Cors
{
    public static function handle(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = trim((string) Env::get('ALLOWED_ORIGINS', '*'));
        $allowedOrigins = array_filter(array_map('trim', explode(',', $allowed)));

        $allowAll = in_array('*', $allowedOrigins, true);
        $isAllowed = false;

        if ($allowAll) {
            $isAllowed = true;
        } elseif ($origin) {
            foreach ($allowedOrigins as $allowedOrigin) {
                $allowedHost = parse_url($allowedOrigin, PHP_URL_HOST);
                $originHost = parse_url($origin, PHP_URL_HOST);

                if (!$allowedHost || !$originHost) {
                    continue;
                }

                // Match root domain and any subdomain including www
                if ($originHost === $allowedHost || str_ends_with($originHost, '.' . $allowedHost)) {
                    $isAllowed = true;
                    break;
                }
            }
        }

        if ($isAllowed) {
            header('Vary: Origin');
            header('Access-Control-Allow-Origin: ' . ($allowAll ? '*' : $origin));
            if (!$allowAll) {
                header('Access-Control-Allow-Credentials: true');
            }
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
            header('Access-Control-Allow-Methods: GET');
            header('Access-Control-Max-Age: 600');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
