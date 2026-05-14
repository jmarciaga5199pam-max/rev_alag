<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\CsrfMiddleware;
use App\Middleware\SessionMiddleware;

class Application
{
    private static ?Application $instance = null;
    private Router $router;
    private Database $db;
    private array $config;

    private function __construct()
    {
        $this->loadEnvironment();
        $this->config = require dirname(__DIR__, 2) . '/config/app.php';

        date_default_timezone_set($this->config['timezone']);

        $this->registerErrorHandler();

        $this->db = Database::getInstance();
        $this->router = new Router();
    }

    /**
     * Global exception handler — converts any uncaught Throwable into a JSON
     * payload for AJAX/XHR clients (so the UI gets {success:false,message:...}
     * instead of a blank 500) and a friendly HTML page otherwise.
     */
    private function registerErrorHandler(): void
    {
        $debug = (bool) ($this->config['debug'] ?? false);

        set_exception_handler(function (\Throwable $e) use ($debug) {
            error_log('[uncaught] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            if (!headers_sent()) {
                http_response_code(500);
            }

            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || str_contains($accept, 'application/json');

            if ($isAjax) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'success' => false,
                    'message' => $debug ? $e->getMessage() : 'An unexpected error occurred. Please try again.',
                    'data' => null,
                ]);
                exit;
            }

            $message = $debug ? htmlspecialchars($e->getMessage()) : 'An unexpected error occurred.';
            echo "<!doctype html><meta charset='utf-8'><title>Server Error</title>"
                . "<div style='font-family:system-ui;padding:40px;max-width:640px;margin:auto;'>"
                . "<h1 style='color:#FF6B9A'>Something went wrong</h1>"
                . "<p>{$message}</p>"
                . "<p><a href='/' style='color:#FF6B9A'>Return to home</a></p>"
                . "</div>";
            exit;
        });
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadEnvironment(): void
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
    }

    public function run(): void
    {
        // Initialize session
        (new SessionMiddleware())->handle();

        // Initialize CSRF
        (new CsrfMiddleware())->init();

        // Load routes
        $routeFile = dirname(__DIR__, 2) . '/routes/web.php';
        if (file_exists($routeFile)) {
            $router = $this->router;
            require $routeFile;
        }

        // Dispatch
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remove base path if needed
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        if ($basePath !== '/' && $basePath !== '\\') {
            $uri = substr($uri, strlen($basePath)) ?: '/';
        }

        $this->router->dispatch($method, $uri);
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getDb(): Database
    {
        return $this->db;
    }

    public function config(string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $keys = explode('.', $key);
        $value = $this->config;
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }
}
