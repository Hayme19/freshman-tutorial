<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * CORS helper — exact origin allowlist loaded from FRONTEND_URL env var.
 *
 * FRONTEND_URL may hold several comma-separated origins, e.g.:
 *   https://freshman-tutorial.netlify.app,http://localhost:5500
 *
 * Per DESIGN §11.5.
 */
final class Cors
{
    public static function handle(): void
    {
        $rawEnv = getenv('FRONTEND_URL');
        if ($rawEnv === false) {
            $rawEnv = $_ENV['FRONTEND_URL'] ?? $_SERVER['FRONTEND_URL'] ?? '';
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) $rawEnv)));
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Credentials: true');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
