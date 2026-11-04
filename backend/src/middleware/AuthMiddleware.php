<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT authentication and role-based authorisation middleware.
 * Per DESIGN §11.4.
 *
 * JWT payload carries: sub (user ObjectId string), role, stream, exp.
 */
final class AuthMiddleware
{
    /**
     * Verify the Bearer token and check the caller has one of the required roles.
     * Use ['any'] to allow any authenticated user regardless of role.
     *
     * Terminates the request (via Response::json → exit) on 401 or 403.
     *
     * @param  string[] $roles
     * @return \stdClass  JWT payload
     */
    public static function require(array $roles): \stdClass
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/', $header, $m)) {
            Response::json(false, null, 'Unauthorized', 401);
        }

        $secret = $_ENV['JWT_SECRET'] ?? $_SERVER['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';

        try {
            $payload = JWT::decode($m[1], new Key((string) $secret, 'HS256'));
        } catch (\Throwable $e) {
            Response::json(false, null, 'Invalid or expired token', 401);
        }

        if (!in_array('any', $roles, true) && !in_array($payload->role ?? '', $roles, true)) {
            Response::json(false, null, 'Forbidden', 403);
        }

        return $payload; // contains sub, role, stream, exp
    }

    /**
     * Load the full user document from the database using the sub in the JWT payload.
     * Returns null if not found or DB is unavailable (caller should treat as 401).
     *
     * @param \stdClass $payload  Return value of require()
     */
    public static function getUser(\stdClass $payload): ?object
    {
        $db  = Database::get();
        $oid = ObjectIdHelper::fromString((string) ($payload->sub ?? ''));
        if ($oid === null || $db->users === null) {
            return null;
        }

        $user = $db->users->findOne(['_id' => $oid]);
        return $user ?? null;
    }

    /**
     * Issue a JWT for a user document.
     *
     * @param array<string,mixed> $userData  Must contain _id, role, stream
     * @return string  Signed JWT
     */
    public static function issueToken(array $userData): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? $_SERVER['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
        $now    = time();

        $payload = [
            'iss' => 'freshman-tutorial',
            'iat' => $now,
            'exp' => $now + 86400, // 24 hours
            'sub' => (string) $userData['_id'],
            'role'   => $userData['role'] ?? 'student',
            'stream' => $userData['stream'] ?? null,
        ];

        return JWT::encode($payload, (string) $secret, 'HS256');
    }
}
