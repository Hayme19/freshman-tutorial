<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\UTCDateTime;

/**
 * Quiz management and attempts — per DESIGN §12.
 *
 * GET  /api/quizzes/{id}         — student (enrolled)
 * POST /api/quizzes/{id}/attempt — student
 * POST /api/quizzes              — instructor, admin (create)
 * PUT  /api/quizzes/{id}         — instructor, admin (update)
 */
final class QuizController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/quizzes/{id} */
    public function get(\stdClass $payload, array $args): void
    {
        $oid  = ObjectIdHelper::requireFromString($args[0] ?? '');
        $quiz = $this->db->quizzes->findOne(['_id' => $oid]);
        if ($quiz === null) Response::json(false, null, 'Quiz not found', 404);

        // Strip correctIndex before sending to student
        $data = ObjectIdHelper::serialize($quiz);
        if ($payload->role === 'student') {
            foreach ($data['questions'] as &$q) {
                unset($q['correctIndex']);
            }
            unset($q);
        }

        Response::json(true, $data, 'OK');
    }

    /** POST /api/quizzes/{id}/attempt */
    public function attempt(\stdClass $payload, array $args): void
    {
        $quizId    = ObjectIdHelper::requireFromString($args[0] ?? '');
        $studentId = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));

        $quiz = $this->db->quizzes->findOne(['_id' => $quizId]);
        if ($quiz === null) Response::json(false, null, 'Quiz not found', 404);

        $body    = $this->body();
        $answers = $body['answers'] ?? [];
        if (!is_array($answers)) Response::json(false, null, 'answers must be an array', 422);

        $questions = (array) ($quiz['questions'] ?? []);
        $total     = count($questions);
        $score     = 0;
        $results   = [];

        foreach ($questions as $i => $q) {
            $given   = isset($answers[$i]) ? (int) $answers[$i] : -1;
            $correct = (int) ($q['correctIndex'] ?? 0);
            $isRight = $given === $correct;
            if ($isRight) $score++;
            $results[] = [
                'questionIndex' => $i,
                'given'         => $given,
                'correct'       => $correct,
                'isCorrect'     => $isRight,
            ];
        }

        $attempt = [
            'quizId'    => $quizId,
            'studentId' => $studentId,
            'score'     => $score,
            'total'     => $total,
            'answers'   => $results,
            'takenAt'   => new UTCDateTime(),
        ];

        $result     = $this->db->quizAttempts->insertOne($attempt);
        $attempt['_id'] = $result->getInsertedId();

        Response::json(true, ObjectIdHelper::serialize($attempt), 'Quiz submitted');
    }

    /** POST /api/quizzes */
    public function create(\stdClass $payload, array $args): void
    {
        $body    = $this->body();
        $allowed = ['courseId', 'lessonId', 'title', 'questions'];
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown) {
            Response::json(false, null, 'Unexpected fields: ' . implode(', ', $unknown), 422);
        }

        $courseId = ObjectIdHelper::requireFromString((string) ($body['courseId'] ?? ''));
        $title    = trim((string) ($body['title'] ?? ''));
        if ($title === '') Response::json(false, null, 'title is required', 422);

        $questions = $body['questions'] ?? [];
        if (!is_array($questions) || empty($questions)) {
            Response::json(false, null, 'questions array is required', 422);
        }

        // Validate each question
        foreach ($questions as $q) {
            if (empty($q['text']) || !isset($q['options']) || !isset($q['correctIndex'])) {
                Response::json(false, null, 'Each question needs text, options, and correctIndex', 422);
            }
        }

        $doc = [
            'courseId'  => $courseId,
            'lessonId'  => isset($body['lessonId'])
                ? ObjectIdHelper::requireFromString((string) $body['lessonId'])
                : null,
            'title'     => $title,
            'questions' => $questions,
        ];

        $result   = $this->db->quizzes->insertOne($doc);
        $doc['_id'] = $result->getInsertedId();
        Response::json(true, $doc, 'Quiz created', 201);
    }

    /** PUT /api/quizzes/{id} */
    public function update(\stdClass $payload, array $args): void
    {
        $oid  = ObjectIdHelper::requireFromString($args[0] ?? '');
        $quiz = $this->db->quizzes->findOne(['_id' => $oid]);
        if ($quiz === null) Response::json(false, null, 'Quiz not found', 404);

        $body    = $this->body();
        $allowed = ['title', 'questions'];
        $set     = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) $set[$f] = $body[$f];
        }

        if (empty($set)) Response::json(false, null, 'Nothing to update', 422);

        $this->db->quizzes->updateOne(['_id' => $oid], ['$set' => $set]);
        $updated = $this->db->quizzes->findOne(['_id' => $oid]);
        Response::json(true, $updated, 'Quiz updated');
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
