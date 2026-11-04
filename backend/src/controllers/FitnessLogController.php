<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\UTCDateTime;

/**
 * Sport / Fitness log — per DESIGN §6 mod15 (Study Tools).
 *
 * GET    /api/fitness-logs         — student (own logs)
 * POST   /api/fitness-logs         — student
 * PUT    /api/fitness-logs/{id}    — student (own)
 * DELETE /api/fitness-logs/{id}    — student (own)
 */
final class FitnessLogController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/fitness-logs */
    public function list(\stdClass $payload, array $args): void
    {
        $userId = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));
        $logs   = $this->db->fitnessLogs
            ->find(['userId' => $userId], ['sort' => ['logDate' => -1]])
            ->toArray();
        Response::json(true, $logs, 'OK');
    }

    /** POST /api/fitness-logs */
    public function create(\stdClass $payload, array $args): void
    {
        $body    = $this->body();
        $userId  = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));
        $allowed = ['activity', 'durationMinutes', 'notes', 'logDate'];
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            Response::json(false, null, 'Unexpected fields: ' . implode(', ', $unknown), 422);
        }

        $activity = trim((string) ($body['activity'] ?? ''));
        if ($activity === '') Response::json(false, null, 'activity is required', 422);

        $doc = [
            'userId'          => $userId,
            'activity'        => $activity,
            'durationMinutes' => (int) ($body['durationMinutes'] ?? 0),
            'notes'           => (string) ($body['notes'] ?? ''),
            'logDate'         => (string) ($body['logDate'] ?? date('Y-m-d')),
            'createdAt'       => new UTCDateTime(),
        ];

        $result   = $this->db->fitnessLogs->insertOne($doc);
        $doc['_id'] = $result->getInsertedId();
        Response::json(true, ObjectIdHelper::serialize($doc), 'Log entry created', 201);
    }

    /** PUT /api/fitness-logs/{id} */
    public function update(\stdClass $payload, array $args): void
    {
        $oid = ObjectIdHelper::requireFromString($args[0] ?? '');
        $log = $this->db->fitnessLogs->findOne(['_id' => $oid]);
        if ($log === null) Response::json(false, null, 'Log not found', 404);
        $this->assertOwner($payload, $log);

        $body    = $this->body();
        $allowed = ['activity', 'durationMinutes', 'notes', 'logDate'];
        $set     = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $set[$f] = $f === 'durationMinutes' ? (int) $body[$f] : (string) $body[$f];
            }
        }
        if (empty($set)) Response::json(false, null, 'Nothing to update', 422);

        $this->db->fitnessLogs->updateOne(['_id' => $oid], ['$set' => $set]);
        $updated = $this->db->fitnessLogs->findOne(['_id' => $oid]);
        Response::json(true, $updated, 'Log updated');
    }

    /** DELETE /api/fitness-logs/{id} */
    public function delete(\stdClass $payload, array $args): void
    {
        $oid = ObjectIdHelper::requireFromString($args[0] ?? '');
        $log = $this->db->fitnessLogs->findOne(['_id' => $oid]);
        if ($log === null) Response::json(false, null, 'Log not found', 404);
        $this->assertOwner($payload, $log);
        $this->db->fitnessLogs->deleteOne(['_id' => $oid]);
        Response::json(true, null, 'Log deleted');
    }

    private function assertOwner(\stdClass $payload, mixed $doc): void
    {
        if ((string) ($doc['userId'] ?? '') !== (string) ($payload->sub ?? '')) {
            Response::json(false, null, 'Forbidden', 403);
        }
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
