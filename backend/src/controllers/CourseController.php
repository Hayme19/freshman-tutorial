<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

/**
 * Course management — per DESIGN §11.3, §12.
 *
 * GET  /api/courses              — public
 * POST /api/courses              — instructor, admin
 * GET  /api/courses/{id}         — public
 * PUT  /api/courses/{id}         — owner or admin
 * DELETE /api/courses/{id}       — owner or admin
 */
final class CourseController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/courses?stream=&group=&q= */
    public function list(\stdClass|null $payload, array $args): void
    {
        $filter = [];

        // Guests and unenrolled: only published courses
        $role = $payload->role ?? 'guest';
        if (!in_array($role, ['instructor', 'admin'], true)) {
            $filter['isPublished'] = true;
        }

        // Whitelisted stream filter (DESIGN §11.3)
        $stream = $_GET['stream'] ?? null;
        if (in_array($stream, ['natural', 'social'], true)) {
            $filter['streams'] = $stream;
        }

        // Whitelisted group filter
        $group = $_GET['group'] ?? null;
        if (in_array($group, ['common', 'natural', 'social', 'digital'], true)) {
            $filter['group'] = $group;
        }

        // Keyword search on title / description (case-insensitive)
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $regex = new Regex(preg_quote($q, '/'), 'i');
            $filter['$or'] = [
                ['title'       => $regex],
                ['description' => $regex],
            ];
        }

        $courses = $this->db->courses
            ->find($filter, ['sort' => ['title' => 1]])
            ->toArray();

        Response::json(true, $courses, 'OK');
    }

    /** GET /api/courses/{id} */
    public function get(\stdClass|null $payload, array $args): void
    {
        $oid    = ObjectIdHelper::requireFromString($args[0] ?? '');
        $filter = ['_id' => $oid];
        $role   = $payload->role ?? 'guest';
        if (!in_array($role, ['instructor', 'admin'], true)) {
            $filter['isPublished'] = true;
        }
        $course = $this->db->courses->findOne($filter);
        if ($course === null) {
            Response::json(false, null, 'Course not found', 404);
        }
        Response::json(true, $course, 'OK');
    }

    /** POST /api/courses */
    public function create(\stdClass $payload, array $args): void
    {
        $body    = $this->body();
        $allowed = ['code', 'title', 'description', 'streams', 'group', 'level', 'isPublished'];
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            Response::json(false, null, 'Unexpected fields: ' . implode(', ', $unknown), 422);
        }

        $title = trim((string) ($body['title'] ?? ''));
        $code  = strtoupper(trim((string) ($body['code'] ?? '')));
        if ($title === '' || $code === '') {
            Response::json(false, null, 'title and code are required', 422);
        }

        $streams = $body['streams'] ?? [];
        if (!is_array($streams)) $streams = [$streams];
        foreach ($streams as $s) {
            if (!in_array($s, ['natural', 'social'], true)) {
                Response::json(false, null, "Invalid stream value: $s", 422);
            }
        }

        $group = $body['group'] ?? 'common';
        if (!in_array($group, ['common', 'natural', 'social', 'digital'], true)) {
            Response::json(false, null, 'group must be common, natural, social, or digital', 422);
        }

        $level = $body['level'] ?? 'beginner';
        if (!in_array($level, ['beginner', 'intermediate'], true)) {
            Response::json(false, null, 'level must be beginner or intermediate', 422);
        }

        $doc = [
            'code'         => $code,
            'title'        => $title,
            'description'  => (string) ($body['description'] ?? ''),
            'streams'      => array_values($streams),
            'group'        => $group,
            'level'        => $level,
            'instructorId' => new ObjectId((string) $payload->sub),
            'isPublished'  => (bool) ($body['isPublished'] ?? false),
            'createdAt'    => new UTCDateTime(),
        ];

        $result = $this->db->courses->insertOne($doc);
        $doc['_id'] = $result->getInsertedId();

        Response::json(true, $doc, 'Course created', 201);
    }

    /** PUT /api/courses/{id} */
    public function update(\stdClass $payload, array $args): void
    {
        $oid    = ObjectIdHelper::requireFromString($args[0] ?? '');
        $course = $this->db->courses->findOne(['_id' => $oid]);
        if ($course === null) {
            Response::json(false, null, 'Course not found', 404);
        }

        $this->assertOwnerOrAdmin($payload, $course);

        $body    = $this->body();
        $allowed = ['title', 'description', 'streams', 'group', 'level', 'isPublished'];
        $set     = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $body)) continue;
            if ($field === 'streams') {
                $streams = is_array($body['streams']) ? $body['streams'] : [$body['streams']];
                foreach ($streams as $s) {
                    if (!in_array($s, ['natural', 'social'], true))
                        Response::json(false, null, "Invalid stream: $s", 422);
                }
                $set['streams'] = array_values($streams);
            } elseif ($field === 'group') {
                if (!in_array($body['group'], ['common', 'natural', 'social', 'digital'], true))
                    Response::json(false, null, 'Invalid group', 422);
                $set['group'] = $body['group'];
            } elseif ($field === 'level') {
                if (!in_array($body['level'], ['beginner', 'intermediate'], true))
                    Response::json(false, null, 'Invalid level', 422);
                $set['level'] = $body['level'];
            } elseif ($field === 'isPublished') {
                $set['isPublished'] = (bool) $body['isPublished'];
            } else {
                $set[$field] = (string) $body[$field];
            }
        }

        if (empty($set)) Response::json(false, null, 'Nothing to update', 422);

        $this->db->courses->updateOne(['_id' => $oid], ['$set' => $set]);
        $updated = $this->db->courses->findOne(['_id' => $oid]);
        Response::json(true, $updated, 'Course updated');
    }

    /** DELETE /api/courses/{id} */
    public function delete(\stdClass $payload, array $args): void
    {
        $oid    = ObjectIdHelper::requireFromString($args[0] ?? '');
        $course = $this->db->courses->findOne(['_id' => $oid]);
        if ($course === null) {
            Response::json(false, null, 'Course not found', 404);
        }
        $this->assertOwnerOrAdmin($payload, $course);
        $this->db->courses->deleteOne(['_id' => $oid]);
        Response::json(true, null, 'Course deleted');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function assertOwnerOrAdmin(\stdClass $payload, mixed $course): void
    {
        if ($payload->role === 'admin') return;
        $instructorId = (string) ($course['instructorId'] ?? '');
        if ($instructorId !== (string) ($payload->sub ?? '')) {
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
