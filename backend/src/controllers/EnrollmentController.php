<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Enrollment — per DESIGN §12.
 * POST /api/enrollments — student
 */
final class EnrollmentController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** POST /api/enrollments */
    public function create(\stdClass $payload, array $args): void
    {
        $body     = $this->body();
        $courseId = ObjectIdHelper::requireFromString((string) ($body['courseId'] ?? ''));
        $studentId = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));

        // Verify the course exists and is published
        $course = $this->db->courses->findOne(['_id' => $courseId, 'isPublished' => true]);
        if ($course === null) {
            Response::json(false, null, 'Course not found or not published', 404);
        }

        // Check already enrolled
        $existing = $this->db->enrollments->findOne([
            'studentId' => $studentId,
            'courseId'  => $courseId,
        ]);
        if ($existing !== null) {
            Response::json(false, null, 'Already enrolled', 409);
        }

        $doc = [
            'studentId'        => $studentId,
            'courseId'         => $courseId,
            'enrolledAt'       => new UTCDateTime(),
            'completedLessons' => [],
            'progressPercent'  => 0,
            'completedAt'      => null,
        ];

        try {
            $result = $this->db->enrollments->insertOne($doc);
        } catch (BulkWriteException $e) {
            // Duplicate key from unique index
            Response::json(false, null, 'Already enrolled', 409);
        }

        $doc['_id'] = $result->getInsertedId();
        Response::json(true, $doc, 'Enrolled successfully', 201);
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
