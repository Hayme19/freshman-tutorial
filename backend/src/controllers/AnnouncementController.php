<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\UTCDateTime;

/**
 * Announcements — per DESIGN §12.
 *
 * GET  /api/announcements — logged-in
 * POST /api/announcements — instructor, admin
 */
final class AnnouncementController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/announcements */
    public function list(\stdClass $payload, array $args): void
    {
        $announcements = $this->db->announcements
            ->find([], ['sort' => ['createdAt' => -1]])
            ->toArray();

        Response::json(true, $announcements, 'OK');
    }

    /** POST /api/announcements */
    public function create(\stdClass $payload, array $args): void
    {
        $body    = $this->body();
        $title   = trim((string) ($body['title'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));

        if ($title === '' || $message === '') {
            Response::json(false, null, 'title and message are required', 422);
        }

        $doc = [
            'authorId'  => ObjectIdHelper::requireFromString((string) ($payload->sub ?? '')),
            'title'     => $title,
            'message'   => $message,
            'createdAt' => new UTCDateTime(),
        ];

        $result   = $this->db->announcements->insertOne($doc);
        $doc['_id'] = $result->getInsertedId();
        Response::json(true, ObjectIdHelper::serialize($doc), 'Announcement created', 201);
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
