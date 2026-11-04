<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\ObjectId;

/**
 * Lesson management — per DESIGN §12.
 *
 * GET  /api/courses/{id}/lessons   — enrolled students see all; guests see isFree only
 * POST /api/lessons                — instructor, admin
 * PUT  /api/lessons/{id}           — instructor, admin
 * DELETE /api/lessons/{id}         — instructor, admin
 */
final class LessonController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/courses/{courseId}/lessons */
    public function listByCourse(\stdClass|null $payload, array $args): void
    {
        $courseId = ObjectIdHelper::requireFromString($args[0] ?? '');

        // Verify course exists
        $course = $this->db->courses->findOne(['_id' => $courseId]);
        if ($course === null) {
            Response::json(false, null, 'Course not found', 404);
        }

        $filter = ['courseId' => $courseId];

        // Unauthenticated or unenrolled: free lessons only
        $showAll = false;
        if ($payload !== null) {
            $role = $payload->role ?? 'student';
            if (in_array($role, ['instructor', 'admin', 'tutor'], true)) {
                $showAll = true;
            } else {
                // Check enrollment
                $userId  = ObjectIdHelper::fromString((string) ($payload->sub ?? ''));
                if ($userId !== null) {
                    $enrolled = $this->db->enrollments->countDocuments([
                        'studentId' => $userId,
                        'courseId'  => $courseId,
                    ]);
                    $showAll = ($enrolled > 0);
                }
            }
        }

        if (!$showAll) {
            $filter['isFree'] = true;
        }

        $lessons = $this->db->lessons
            ->find($filter, ['sort' => ['unit' => 1, 'order' => 1]])
            ->toArray();

        Response::json(true, $lessons, 'OK');
    }

    /** POST /api/lessons */
    public function create(\stdClass $payload, array $args): void
    {
        $body    = $this->body();
        $allowed = ['courseId', 'unit', 'title', 'content', 'codeExample', 'videoUrl', 'order', 'isFree'];
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            Response::json(false, null, 'Unexpected fields: ' . implode(', ', $unknown), 422);
        }

        $courseId = ObjectIdHelper::requireFromString((string) ($body['courseId'] ?? ''));
        $title    = trim((string) ($body['title'] ?? ''));
        if ($title === '') Response::json(false, null, 'title is required', 422);

        $doc = [
            'courseId'    => $courseId,
            'unit'        => (int) ($body['unit'] ?? 1),
            'title'       => $title,
            'content'     => (string) ($body['content'] ?? ''),
            'codeExample' => (string) ($body['codeExample'] ?? ''),
            'videoUrl'    => (string) ($body['videoUrl'] ?? ''),
            'order'       => (int) ($body['order'] ?? 1),
            'isFree'      => (bool) ($body['isFree'] ?? false),
        ];

        $result   = $this->db->lessons->insertOne($doc);
        $doc['_id'] = $result->getInsertedId();

        Response::json(true, $doc, 'Lesson created', 201);
    }

    /** PUT /api/lessons/{id} */
    public function update(\stdClass $payload, array $args): void
    {
        $oid    = ObjectIdHelper::requireFromString($args[0] ?? '');
        $lesson = $this->db->lessons->findOne(['_id' => $oid]);
        if ($lesson === null) Response::json(false, null, 'Lesson not found', 404);

        $body    = $this->body();
        $allowed = ['unit', 'title', 'content', 'codeExample', 'videoUrl', 'order', 'isFree'];
        $set     = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $body)) continue;
            $set[$field] = match($field) {
                'unit', 'order'    => (int) $body[$field],
                'isFree'           => (bool) $body[$field],
                default            => (string) $body[$field],
            };
        }

        if (empty($set)) Response::json(false, null, 'Nothing to update', 422);

        $this->db->lessons->updateOne(['_id' => $oid], ['$set' => $set]);
        $updated = $this->db->lessons->findOne(['_id' => $oid]);
        Response::json(true, $updated, 'Lesson updated');
    }

    /** DELETE /api/lessons/{id} */
    public function delete(\stdClass $payload, array $args): void
    {
        $oid    = ObjectIdHelper::requireFromString($args[0] ?? '');
        $lesson = $this->db->lessons->findOne(['_id' => $oid]);
        if ($lesson === null) Response::json(false, null, 'Lesson not found', 404);
        $this->db->lessons->deleteOne(['_id' => $oid]);
        Response::json(true, null, 'Lesson deleted');
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
