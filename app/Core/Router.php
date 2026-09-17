<?php

namespace App\Core;

class Router
{
    private array $routes = [];

    public function get(string $path, array|callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, array|callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(string $method, string $path, array|callable $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'path'    => '/' . trim($path, '/'),
            'handler' => $handler,
        ];
    }

    /**
     * Dispatch the current HTTP request to the matching route handler
     */
    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Normalize URI in subdirectories (e.g. /slitting_system/login -> /login)
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        $uri = '/' . trim($uri, '/');

        // Check query string route fallback (e.g. ?r=login)
        if (isset($_GET['r'])) {
            $uri = '/' . trim($_GET['r'], '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && $route['path'] === $uri) {
                $this->executeHandler($route['handler']);
                return;
            }
        }

        // 404 fallback
        http_response_code(404);
        echo "<h1>404 Not Found</h1><p>Route {$uri} not found.</p>";
    }

    private function executeHandler(array|callable $handler): void
    {
        if (is_callable($handler)) {
            call_user_func($handler);
        } elseif (is_array($handler) && count($handler) === 2) {
            [$controllerClass, $method] = $handler;
            $controller = new $controllerClass();
            $controller->$method();
        }
    }
}
