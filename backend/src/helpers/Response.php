<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\ObjectIdHelper;

/**
 * Single JSON response shape: { success, data, message }.
 *
 * Calls exit after output so middleware such as AuthMiddleware::require() can
 * terminate the request by delegating to Response::json(). Safe to call even if
 * headers have already been sent (it skips the header() calls in that case).
 */
final class Response
{
    /**
     * @param mixed $data
     */
    public static function json($success, $data, string $message = '', int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }

        $payload = [
            'success' => (bool) $success,
            'data'    => ObjectIdHelper::serialize($data),
            'message' => $message,
        ];

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
