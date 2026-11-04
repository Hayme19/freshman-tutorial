<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;

/**
 * Admin panel — per DESIGN §12.
 *
 * GET /api/admin/users               — admin
 * PUT /api/admin/users/{id}/role     — admin
 */
final class AdminController
{
    private Database $db;
    private const VALID_ROLES = ['student', 'tutor', 'instructor', 'admin'];

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/admin/users?page=1&limit=20&role=&q= */
    public function listUsers(\stdClass $payload, array $args): void
    {
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $skip  = ($page - 1) * $limit;

        $filter = [];

        $role = $_GET['role'] ?? '';
        if (in_array($role, self::VALID_ROLES, true)) {
            $filter['role'] = $role;
        }

        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $regex = new \MongoDB\BSON\Regex(preg_quote($q, '/'), 'i');
            $filter['$or'] = [
                ['fullName' => $regex],
                ['email'    => $regex],
            ];
        }

        $total = $this->db->users->countDocuments($filter);
        $users = $this->db->users
            ->find($filter, [
                'skip'       => $skip,
                'limit'      => $limit,
                'sort'       => ['createdAt' => -1],
                'projection' => ['passwordHash' => 0],
            ])
            ->toArray();

        Response::json(true, [
            'users' => $users,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ], 'OK');
    }

    /** PUT /api/admin/users/{id}/role */
    public function updateRole(\stdClass $payload, array $args): void
    {
        $targetId = ObjectIdHelper::requireFromString($args[0] ?? '');
        $body     = $this->body();
        $newRole  = (string) ($body['role'] ?? '');

        if (!in_array($newRole, self::VALID_ROLES, true)) {
            Response::json(false, null, 'role must be one of: ' . implode(', ', self::VALID_ROLES), 422);
        }

        // Prevent removing the last admin
        if ($newRole !== 'admin') {
            $target = $this->db->users->findOne(['_id' => $targetId]);
            if ($target === null) Response::json(false, null, 'User not found', 404);
            if (($target['role'] ?? '') === 'admin') {
                $adminCount = $this->db->users->countDocuments(['role' => 'admin']);
                if ($adminCount <= 1) {
                    Response::json(false, null, 'Cannot remove the last admin', 409);
                }
            }
        }

        $result = $this->db->users->updateOne(
            ['_id' => $targetId],
            ['$set' => ['role' => $newRole]]
        );

        if ($result->getMatchedCount() === 0) {
            Response::json(false, null, 'User not found', 404);
        }

        Response::json(true, ['role' => $newRole], 'Role updated');
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
