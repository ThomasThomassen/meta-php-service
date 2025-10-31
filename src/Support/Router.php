<?php
declare(strict_types=1);

namespace App\Support;

class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->map('GET', $path, $handler);
    }

    private function map(string $method, string $path, callable|array $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $handler = $this->routes[$method][$path] ?? null;
        if (!$handler) {
            http_response_code(404);
            return Response::json(['error' => 'not_found']);
        }
        if (is_array($handler)) {
            [$class, $methodName] = $handler;
            $instance = new $class();
            return (string) ($instance->$methodName());
        }
        return (string) $handler();
    }
}
