<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\UTCDateTime;

/**
 * Exercise submissions — per DESIGN §12.
 *
 * POST /api/submissions              — student
 * PUT  /api/submissions/{id}/grade   — instructor, tutor
 */
final class SubmissionController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** POST /api/submissions */
    public function create(\stdClass $payload, array $args): void
    {
        $body       = $this->body();
        $exerciseId = ObjectIdHelper::requireFromString((string) ($body['exerciseId'] ?? ''));
        $studentId  = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));

        $exercise = $this->db->exercises->findOne(['_id' => $exerciseId]);
        if ($exercise === null) Response::json(false, null, 'Exercise not found', 404);

        $code = (string) ($body['code'] ?? '');
        if ($code === '') Response::json(false, null, 'code is required', 422);

        $doc = [
            'exerciseId'  => $exerciseId,
            'studentId'   => $studentId,
            'code'        => $code,
            'status'      => 'pending',
            'grade'       => 0,
            'feedback'    => '',
            'submittedAt' => new UTCDateTime(),
        ];

        $result   = $this->db->submissions->insertOne($doc);
        $doc['_id'] = $result->getInsertedId();
        Response::json(true, ObjectIdHelper::serialize($doc), 'Submission received', 201);
    }

    /** PUT /api/submissions/{id}/grade */
    public function grade(\stdClass $payload, array $args): void
    {
        $oid        = ObjectIdHelper::requireFromString($args[0] ?? '');
        $submission = $this->db->submissions->findOne(['_id' => $oid]);
        if ($submission === null) Response::json(false, null, 'Submission not found', 404);

        $body = $this->body();
        $set  = ['status' => 'reviewed'];

        if (isset($body['grade'])) {
            $grade = (int) $body['grade'];
            if ($grade < 0 || $grade > 100) Response::json(false, null, 'grade must be 0–100', 422);
            $set['grade'] = $grade;
        }

        if (isset($body['feedback'])) {
            $set['feedback'] = (string) $body['feedback'];
        }

        $this->db->submissions->updateOne(['_id' => $oid], ['$set' => $set]);
        $updated = $this->db->submissions->findOne(['_id' => $oid]);
        Response::json(true, $updated, 'Submission graded');
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
