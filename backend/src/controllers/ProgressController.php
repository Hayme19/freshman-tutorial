<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\UTCDateTime;

/**
 * Progress tracking — per DESIGN §11.8, §12.
 *
 * POST /api/progress/complete-lesson — student
 * GET  /api/progress/me              — student
 */
final class ProgressController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** POST /api/progress/complete-lesson */
    public function completeLesson(\stdClass $payload, array $args): void
    {
        $body      = $this->body();
        $lessonId  = ObjectIdHelper::requireFromString((string) ($body['lessonId'] ?? ''));
        $studentId = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));

        // Resolve courseId from the lesson
        $lesson = $this->db->lessons->findOne(['_id' => $lessonId]);
        if ($lesson === null) Response::json(false, null, 'Lesson not found', 404);
        $courseId = $lesson['courseId'];

        // Verify enrollment
        $enrollment = $this->db->enrollments->findOne([
            'studentId' => $studentId,
            'courseId'  => $courseId,
        ]);
        if ($enrollment === null) {
            Response::json(false, null, 'Not enrolled in this course', 403);
        }

        // $addToSet so repeats are ignored
        $this->db->enrollments->updateOne(
            ['studentId' => $studentId, 'courseId' => $courseId],
            ['$addToSet' => ['completedLessons' => $lessonId]]
        );

        // Recalculate progress
        $totalLessons = $this->db->lessons->countDocuments(['courseId' => $courseId]);
        $enrollment   = $this->db->enrollments->findOne([
            'studentId' => $studentId,
            'courseId'  => $courseId,
        ]);

        $completedCount = count((array) ($enrollment['completedLessons'] ?? []));
        $percent        = $totalLessons > 0
            ? (int) round($completedCount / $totalLessons * 100)
            : 0;

        $updateSet = ['progressPercent' => $percent];

        // Check for course completion: 100% + final quiz ≥ 50%
        if ($percent >= 100) {
            $passed = $this->hasFinalQuizPassed($studentId, $courseId);
            if ($passed && $enrollment['completedAt'] === null) {
                $updateSet['completedAt'] = new UTCDateTime();
                $this->createCertificate($studentId, $courseId);
            }
        }

        $this->db->enrollments->updateOne(
            ['studentId' => $studentId, 'courseId' => $courseId],
            ['$set' => $updateSet]
        );

        Response::json(true, ['progressPercent' => $percent], 'Progress updated');
    }

    /** GET /api/progress/me */
    public function getMyProgress(\stdClass $payload, array $args): void
    {
        $studentId  = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));
        $enrollments = $this->db->enrollments
            ->find(['studentId' => $studentId])
            ->toArray();

        // Enrich with course title and quiz history
        $result = [];
        foreach ($enrollments as $e) {
            $courseId = $e['courseId'];
            $course   = $this->db->courses->findOne(['_id' => $courseId], ['projection' => ['title' => 1]]);

            $attempts = $this->db->quizAttempts
                ->find(['studentId' => $studentId], ['sort' => ['takenAt' => -1]])
                ->toArray();

            $row              = ObjectIdHelper::serialize($e);
            $row['courseTitle'] = ObjectIdHelper::serialize($course['title'] ?? '');
            $row['quizHistory'] = ObjectIdHelper::serialize($attempts);
            $result[]           = $row;
        }

        Response::json(true, $result, 'OK');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function hasFinalQuizPassed(mixed $studentId, mixed $courseId): bool
    {
        // Find quizzes for this course
        $quizzes = $this->db->quizzes->find(['courseId' => $courseId])->toArray();
        if (empty($quizzes)) return false;

        foreach ($quizzes as $quiz) {
            $attempt = $this->db->quizAttempts->findOne(
                ['quizId' => $quiz['_id'], 'studentId' => $studentId],
                ['sort' => ['takenAt' => -1]]
            );
            if ($attempt !== null) {
                $total = (int) ($attempt['total'] ?? 0);
                $score = (int) ($attempt['score'] ?? 0);
                if ($total > 0 && ($score / $total) >= 0.5) {
                    return true;
                }
            }
        }
        return false;
    }

    private function createCertificate(mixed $studentId, mixed $courseId): void
    {
        $existing = $this->db->certificates->findOne([
            'studentId' => $studentId,
            'courseId'  => $courseId,
        ]);
        if ($existing !== null) return;

        $course = $this->db->courses->findOne(['_id' => $courseId], ['projection' => ['title' => 1]]);
        $user   = $this->db->users->findOne(['_id' => $studentId], ['projection' => ['fullName' => 1]]);

        $this->db->certificates->insertOne([
            'studentId'   => $studentId,
            'courseId'    => $courseId,
            'courseTitle' => $course['title'] ?? '',
            'studentName' => $user['fullName'] ?? '',
            'issuedAt'    => new UTCDateTime(),
        ]);
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
