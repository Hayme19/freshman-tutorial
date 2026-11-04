<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\UTCDateTime;

/**
 * Authentication: register, login, me.
 * POST /api/auth/register  — public
 * POST /api/auth/login     — public
 * GET  /api/auth/me        — any authenticated user
 */
final class AuthController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
        if ($this->db->getError() !== null) {
            Response::json(false, null, 'Database unavailable', 503);
        }
    }

    /** POST /api/auth/register */
    public function register(\stdClass|null $user, array $args): void
    {
        $body = $this->body();

        // Validate required fields only — reject unexpected keys
        $allowed  = ['fullName', 'email', 'password', 'stream'];
        $unknown  = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            Response::json(false, null, 'Unexpected fields: ' . implode(', ', $unknown), 422);
        }

        $fullName = trim((string) ($body['fullName'] ?? ''));
        $email    = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $stream   = $body['stream'] ?? null;

        if ($fullName === '' || $email === '' || $password === '') {
            Response::json(false, null, 'fullName, email and password are required', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(false, null, 'Invalid email address', 422);
        }
        if (strlen($password) < 8) {
            Response::json(false, null, 'Password must be at least 8 characters', 422);
        }
        if ($stream !== null && !in_array($stream, ['natural', 'social'], true)) {
            Response::json(false, null, 'stream must be natural or social', 422);
        }

        // Unique email check
        if ($this->db->users->countDocuments(['email' => $email]) > 0) {
            Response::json(false, null, 'Email already registered', 409);
        }

        $doc = [
            'fullName'     => $fullName,
            'email'        => $email,
            'passwordHash' => password_hash($password, PASSWORD_BCRYPT),
            'role'         => 'student',
            'stream'       => $stream,
            'avatarUrl'    => '',
            'isActive'     => true,
            'createdAt'    => new UTCDateTime(),
        ];

        $result = $this->db->users->insertOne($doc);
        $id     = $result->getInsertedId();

        $token = AuthMiddleware::issueToken([
            '_id'    => $id,
            'role'   => 'student',
            'stream' => $stream,
        ]);

        Response::json(true, [
            'token' => $token,
            'user'  => [
                '_id'      => ObjectIdHelper::serialize($id),
                'fullName' => $fullName,
                'email'    => $email,
                'role'     => 'student',
                'stream'   => $stream,
            ],
        ], 'Registration successful', 201);
    }

    /** POST /api/auth/login */
    public function login(\stdClass|null $user, array $args): void
    {
        $body = $this->body();

        $email    = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::json(false, null, 'email and password are required', 422);
        }

        // Rate-limit: 5 failures per 15 min per email+IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->checkRateLimit($email, $ip);

        $found = $this->db->users->findOne(['email' => $email]);

        if ($found === null || !password_verify($password, (string) ($found['passwordHash'] ?? ''))) {
            // Record failure
            $this->recordFailedLogin($email, $ip);
            Response::json(false, null, 'Invalid email or password', 401);
        }

        if (!($found['isActive'] ?? true)) {
            Response::json(false, null, 'Account is disabled', 403);
        }

        // Clear failed-login counter on success
        $this->db->loginAttempts->deleteMany(['key' => $this->loginKey($email, $ip)]);

        $token = AuthMiddleware::issueToken([
            '_id'    => $found['_id'],
            'role'   => $found['role'] ?? 'student',
            'stream' => $found['stream'] ?? null,
        ]);

        // Return user without passwordHash
        $safe = ObjectIdHelper::serialize($found);
        unset($safe['passwordHash']);

        Response::json(true, ['token' => $token, 'user' => $safe], 'Login successful');
    }

    /** GET /api/auth/me */
    public function me(\stdClass|null $payload, array $args): void
    {
        if ($payload === null) {
            Response::json(false, null, 'Unauthorized', 401);
        }
        $oid  = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));
        $user = $this->db->users->findOne(['_id' => $oid]);
        if ($user === null) {
            Response::json(false, null, 'User not found', 404);
        }
        $safe = ObjectIdHelper::serialize($user);
        unset($safe['passwordHash']);
        Response::json(true, $safe, 'OK');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function body(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function loginKey(string $email, string $ip): string
    {
        return md5($email . '|' . $ip);
    }

    private function checkRateLimit(string $email, string $ip): void
    {
        $key      = $this->loginKey($email, $ip);
        $windowMs = 15 * 60; // 15 minutes in seconds
        $cutoff   = new UTCDateTime((time() - $windowMs) * 1000);

        $count = $this->db->loginAttempts->countDocuments([
            'key'       => $key,
            'createdAt' => ['$gte' => $cutoff],
        ]);

        if ($count >= 5) {
            Response::json(false, null, 'Too many failed attempts. Try again in 15 minutes.', 429);
        }
    }

    private function recordFailedLogin(string $email, string $ip): void
    {
        $this->db->loginAttempts->insertOne([
            'key'       => $this->loginKey($email, $ip),
            'email'     => $email,
            'ip'        => $ip,
            'createdAt' => new UTCDateTime(),
        ]);
    }
}
