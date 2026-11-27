<?php
declare(strict_types=1);

namespace AnaBot\Core;

use Throwable;

/**
 * AnaBot Application Bootstrap & Service Container
 */
class App
{
    private static ?self $instance = null;
    private array $services = [];
    private Router $router;
    private bool $booted = false;

    private function __construct()
    {
        $this->router = new Router();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        // Configure Timezone
        date_default_timezone_set((string) config('app.timezone', 'Asia/Jakarta'));

        // Register Error & Exception Handlers
        $this->registerErrorHandlers();

        // Register Core Singletons
        $this->singleton('db', fn() => Database::getInstance());
        $this->singleton('logger', fn() => new Logger());
        $this->singleton('encryption', fn() => new Encryption());

        $this->booted = true;
        return $this;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            $sessionConfig = (array) config('security.session', []);

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');

            session_name((string) ($sessionConfig['name'] ?? 'ANABOT_SESSION'));

            session_set_cookie_params([
                'lifetime' => (int) ($sessionConfig['lifetime'] ?? 7200),
                'path' => (string) ($sessionConfig['path'] ?? '/'),
                'domain' => $sessionConfig['domain'] ?? '',
                'secure' => $isHttps || (bool) ($sessionConfig['secure'] ?? false),
                'httponly' => (bool) ($sessionConfig['httponly'] ?? true),
                'samesite' => (string) ($sessionConfig['samesite'] ?? 'Lax'),
            ]);
        }

        @session_start();
    }

    public function singleton(string $name, callable $resolver): void
    {
        $this->services[$name] = [
            'resolver' => $resolver,
            'instance' => null,
            'is_singleton' => true,
        ];
    }

    public function bind(string $name, callable $resolver): void
    {
        $this->services[$name] = [
            'resolver' => $resolver,
            'instance' => null,
            'is_singleton' => false,
        ];
    }

    public function get(string $name): mixed
    {
        if (!isset($this->services[$name])) {
            throw new \RuntimeException("Service [{$name}] is not registered in container.");
        }

        $service = &$this->services[$name];
        if ($service['is_singleton']) {
            if ($service['instance'] === null) {
                $service['instance'] = ($service['resolver'])($this);
            }
            return $service['instance'];
        }

        return ($service['resolver'])($this);
    }

    public function has(string $name): bool
    {
        return isset($this->services[$name]);
    }

    public function run(?string $method = null, ?string $uri = null): mixed
    {
        $this->boot();
        $this->startSession();
        return $this->router->dispatch($method, $uri);
    }

    private function registerErrorHandlers(): void
    {
        error_reporting(E_ALL);

        set_error_handler(function (int $level, string $message, string $file, int $line) {
            if (!(error_reporting() & $level)) {
                return false;
            }
            throw new \ErrorException($message, 0, $level, $file, $line);
        });

        set_exception_handler(function (Throwable $e) {
            $this->handleException($e);
        });
    }

    public function handleException(Throwable $e): void
    {
        $isDebug = (bool) config('app.debug', false);

        // Always log full error securely
        if ($this->has('logger')) {
            $this->get('logger')->critical($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'code' => $e->getCode(),
            ]);
        }

        // Handle CLI environment
        if (is_cli()) {
            echo PHP_EOL . "=== Application Exception (CLI) ===" . PHP_EOL;
            echo "Message: " . $e->getMessage() . PHP_EOL;
            echo "Location: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
            if ($isDebug) {
                echo "Trace:" . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
            }
            exit(1);
        }

        $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');

        if ($isApi) {
            $details = $isDebug ? [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ] : null;

            Response::error(
                $isDebug ? $e->getMessage() : 'Terjadi kesalahan internal server.',
                'INTERNAL_ERROR',
                $details,
                500
            );
        }

        if (!headers_sent()) {
            http_response_code(500);
        }

        if ($isDebug) {
            echo "<h1>Application Error (Debug Mode)</h1>";
            echo "<p><strong>Message:</strong> " . e($e->getMessage()) . "</p>";
            echo "<p><strong>Location:</strong> " . e($e->getFile()) . " line " . $e->getLine() . "</p>";
            echo "<pre>" . e($e->getTraceAsString()) . "</pre>";
        } else {
            echo "<h1>500 Internal Server Error</h1>";
            echo "<p>Terjadi kesalahan internal pada sistem. Detail error telah dicatat ke audit log.</p>";
        }
        exit(1);
    }
}
