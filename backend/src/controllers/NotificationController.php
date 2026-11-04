<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;

/**
 * Notifications — per DESIGN §6 mod11.
 *
 * GET  /api/notifications        — logged-in (own notifications)
 * PUT  /api/notifications/{id}/read — logged-in
 */
final class NotificationController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/notifications */
    public function list(\stdClass $payload, array $args): void
    {
        $userId = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));
        $notes  = $this->db->notifications
            ->find(['userId' => $userId], ['sort' => ['createdAt' => -1]])
            ->toArray();
        Response::json(true, $notes, 'OK');
    }

    /** PUT /api/notifications/{id}/read */
    public function markRead(\stdClass $payload, array $args): void
    {
        $oid  = ObjectIdHelper::requireFromString($args[0] ?? '');
        $note = $this->db->notifications->findOne(['_id' => $oid]);
        if ($note === null) Response::json(false, null, 'Notification not found', 404);

        if ((string) $note['userId'] !== (string) ($payload->sub ?? '')) {
            Response::json(false, null, 'Forbidden', 403);
        }

        $this->db->notifications->updateOne(['_id' => $oid], ['$set' => ['isRead' => true]]);
        Response::json(true, null, 'Marked as read');
    }
}
