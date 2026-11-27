<?php
declare(strict_types=1);

namespace AnaBot\Core;

/**
 * AnaBot Lightweight Modular Fast Router
 */
class Router
{
    private array $routes = [];
    private string $currentPrefix = '';
    private array $currentMiddleware = [];

    public function get(string $path, callable|array|string $handler, array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array|string $handler, array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array|string $handler, array $middleware = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable|array|string $handler, array $middleware = []): self
    {
        return $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array|string $handler, array $middleware = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->currentPrefix;
        $previousMiddleware = $this->currentMiddleware;

        if (isset($attributes['prefix'])) {
            $this->currentPrefix = rtrim($previousPrefix, '/') . '/' . trim($attributes['prefix'], '/');
        }

        if (isset($attributes['middleware'])) {
            $middlewares = (array) $attributes['middleware'];
            $this->currentMiddleware = array_merge($previousMiddleware, $middlewares);
        }

        $callback($this);

        $this->currentPrefix = $previousPrefix;
        $this->currentMiddleware = $previousMiddleware;
    }

    private function addRoute(string $method, string $path, callable|array|string $handler, array $middleware = []): self
    {
        $fullPath = rtrim($this->currentPrefix, '/') . '/' . ltrim($path, '/');
        if ($fullPath !== '/') {
            $fullPath = rtrim($fullPath, '/');
        }

        $allMiddleware = array_merge($this->currentMiddleware, $middleware);

        // Convert {param} to regex pattern: ([^/]+)
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $fullPath);
        $regex = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $allMiddleware,
        ];

        return $this;
    }

    public function dispatch(?string $method = null, ?string $uri = null): mixed
    {
        $requestMethod = strtoupper($method ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
        
        // Handle method override for forms (e.g. _method=PATCH/DELETE)
        if ($requestMethod === 'POST' && isset($_POST['_method'])) {
            $requestMethod = strtoupper((string) $_POST['_method']);
        }

        $rawUri = $uri ?? $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($rawUri, PHP_URL_PATH) ?: '/';

        // Auto-strip base directory if running in a subdirectory (e.g. /anabot or /anabot/public)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = trim(str_replace('\\', '/', dirname($scriptName)), '/');
        if (!empty($scriptDir) && $scriptDir !== '.') {
            if (str_starts_with($path, '/' . $scriptDir)) {
                $path = substr($path, strlen('/' . $scriptDir));
            } elseif (str_ends_with($scriptDir, '/public')) {
                $parentDir = substr($scriptDir, 0, -7);
                if (!empty($parentDir) && str_starts_with($path, '/' . $parentDir)) {
                    $path = substr($path, strlen('/' . $parentDir));
                }
            } elseif ($scriptDir === 'public' && str_starts_with($path, '/public')) {
                $path = substr($path, 7);
            }
        }

        $path = rtrim($path, '/');
        if (empty($path)) {
            $path = '/';
        }

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                if ($route['method'] !== $requestMethod) {
                    $allowedMethods[] = $route['method'];
                    continue;
                }

                // Extract named parameters
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Execute middlewares
                $this->runMiddleware($route['middleware']);

                // Execute handler
                return $this->executeHandler($route['handler'], $params);
            }
        }

        if (!empty($allowedMethods)) {
            if ($this->isApiRequest($path)) {
                Response::error("Method {$requestMethod} not allowed. Allowed: " . implode(', ', array_unique($allowedMethods)), 'METHOD_NOT_ALLOWED', null, 405);
            }
            http_response_code(405);
            echo "405 Method Not Allowed";
            exit;
        }

        if ($this->isApiRequest($path)) {
            Response::error("Endpoint [{$path}] not found.", 'NOT_FOUND', null, 404);
        }

        http_response_code(404);
        echo "404 Not Found";
        exit;
    }

    private function runMiddleware(array $middlewares): void
    {
        foreach ($middlewares as $mw) {
            if ($mw === 'auth') {
                if (!Auth::check()) {
                    if ($this->isApiRequest()) {
                        Response::error('Unauthenticated.', 'UNAUTHENTICATED', null, 401);
                    }
                    Response::redirect('/login');
                }
            } elseif ($mw === 'guest') {
                if (Auth::check()) {
                    Response::redirect('/dashboard');
                }
            } elseif ($mw === 'csrf') {
                $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
                if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                    $sessionToken = $_SESSION['_csrf_token'] ?? '';
                    if (empty($sessionToken) || !hash_equals($sessionToken, (string) $token)) {
                        if ($this->isApiRequest()) {
                            Response::error('CSRF token mismatch or expired.', 'CSRF_FAILED', null, 403);
                        }
                        http_response_code(403);
                        echo "403 Forbidden - Invalid CSRF Token";
                        exit;
                    }
                }
            }
        }
    }

    private function executeHandler(callable|array|string $handler, array $params): mixed
    {
        if (is_callable($handler)) {
            return $handler($params);
        }

        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            return $controller->$method($params);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $fullClass = str_starts_with($class, 'AnaBot\\') ? $class : "AnaBot\\Controllers\\{$class}";
            $controller = new $fullClass();
            return $controller->$method($params);
        }

        throw new \RuntimeException('Invalid route handler configuration.');
    }

    private function isApiRequest(?string $path = null): bool
    {
        $checkPath = $path ?? ($_SERVER['REQUEST_URI'] ?? '');
        return str_starts_with($checkPath, '/api/');
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
}
