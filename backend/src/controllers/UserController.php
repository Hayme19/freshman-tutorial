<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;

/**
 * User profile management.
 * PUT /api/users/me — logged-in (any role)
 */
final class UserController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** PUT /api/users/me */
    public function updateMe(\stdClass $payload, array $args): void
    {
        $body    = $this->body();
        $allowed = ['fullName', 'avatarUrl', 'stream'];
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            Response::json(false, null, 'Unexpected fields: ' . implode(', ', $unknown), 422);
        }

        $set = [];

        if (isset($body['fullName'])) {
            $name = trim((string) $body['fullName']);
            if ($name === '') Response::json(false, null, 'fullName cannot be empty', 422);
            $set['fullName'] = $name;
        }

        if (isset($body['avatarUrl'])) {
            $set['avatarUrl'] = (string) $body['avatarUrl'];
        }

        if (array_key_exists('stream', $body)) {
            $stream = $body['stream'];
            if ($stream !== null && !in_array($stream, ['natural', 'social'], true)) {
                Response::json(false, null, 'stream must be natural, social, or null', 422);
            }
            $set['stream'] = $stream;
        }

        if (empty($set)) {
            Response::json(false, null, 'Nothing to update', 422);
        }

        $oid = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));
        $this->db->users->updateOne(['_id' => $oid], ['$set' => $set]);

        $user = $this->db->users->findOne(['_id' => $oid]);
        $safe = ObjectIdHelper::serialize($user);
        unset($safe['passwordHash']);

        Response::json(true, $safe, 'Profile updated');
    }

    private function body(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
