<?php
/**
 * Centralized CORS & Session Handler for API Endpoints
 * Civentral Public Assets
 */

// 1. Session Initialization (prevent session lock issues)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Load .env variables if not already loaded into getenv / $_ENV
if (!function_exists('loadCorsEnv')) {
    function loadCorsEnv($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}
loadCorsEnv(__DIR__ . '/../.env');

// 3. Centralized CORS Evaluation Function
if (!function_exists('handleCorsHeaders')) {
    function handleCorsHeaders() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $httpHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
        $serverHost = explode(':', $httpHost)[0];

        // Default localhost and 127.0.0.1 origins for local XAMPP/Docker development
        $defaultLocalOrigins = [
            'http://localhost',
            'http://localhost:80',
            'http://localhost:3000',
            'http://localhost:5173',
            'http://localhost:8080',
            'http://127.0.0.1',
            'http://127.0.0.1:80',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:8080',
            'https://localhost',
            'https://127.0.0.1'
        ];

        // Parse optional comma-separated ALLOWED_ORIGINS from .env
        $envAllowedStr = getenv('ALLOWED_ORIGINS') ?: ($_ENV['ALLOWED_ORIGINS'] ?? $_SERVER['ALLOWED_ORIGINS'] ?? '');
        $configuredOrigins = [];
        if (!empty($envAllowedStr)) {
            $rawList = explode(',', $envAllowedStr);
            foreach ($rawList as $item) {
                $item = trim($item);
                if (!empty($item)) {
                    $configuredOrigins[] = rtrim($item, '/');
                }
            }
        }

        $allowOrigin = null;

        if (!empty($origin)) {
            $originClean = rtrim($origin, '/');
            $originHost = parse_url($origin, PHP_URL_HOST) ?: '';
            $originPort = parse_url($origin, PHP_URL_PORT);
            $originHostWithPort = $originHost . ($originPort ? ':' . $originPort : '');

            // Rule A: Localhost / 127.0.0.1 fallback
            if (in_array($originClean, $defaultLocalOrigins, true) ||
                preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i', $originClean)) {
                $allowOrigin = $origin;
            }

            // Rule B: Match current request domain (HTTP_HOST / HTTP_X_FORWARDED_HOST)
            if (!$allowOrigin && !empty($httpHost)) {
                if ($originHost === $httpHost || 
                    $originHostWithPort === $httpHost || 
                    $originHost === $serverHost) {
                    $allowOrigin = $origin;
                }
            }

            // Rule B2: Check if origin host matches current server name or address
            if (!$allowOrigin && !empty($_SERVER['SERVER_NAME'])) {
                if ($originHost === $_SERVER['SERVER_NAME']) {
                    $allowOrigin = $origin;
                }
            }

            // Rule C: Configured ALLOWED_ORIGINS in .env
            if (!$allowOrigin && !empty($configuredOrigins)) {
                foreach ($configuredOrigins as $allowed) {
                    if ($allowed === '*' ||
                        $originClean === $allowed ||
                        $originHost === $allowed ||
                        $originHostWithPort === $allowed ||
                        $originClean === 'http://' . $allowed ||
                        $originClean === 'https://' . $allowed) {
                        $allowOrigin = $origin;
                        break;
                    }
                }
            }
        } elseif (!empty($httpHost)) {
            // When HTTP_ORIGIN is absent (same-origin GET/POST in some browsers),
            // construct the origin from the current request scheme and host.
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                     (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                     ? 'https'
                     : 'http';
            $allowOrigin = "{$proto}://{$httpHost}";
        }

        // Send CORS response headers if allowed origin is determined
        if (!headers_sent()) {
            if (!empty($allowOrigin)) {
                header("Access-Control-Allow-Origin: {$allowOrigin}");
                header('Access-Control-Allow-Credentials: true');
            }

            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Content-Type: application/json; charset=utf-8');
        }

        // Clean early exit for HTTP OPTIONS preflight requests
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}

// Automatically apply CORS headers when included
handleCorsHeaders();
