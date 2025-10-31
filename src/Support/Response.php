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
}
