<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;

/**
 * Reports and analytics — per DESIGN §12, §6 mod13.
 *
 * GET /api/reports/summary — admin, instructor
 */
final class ReportController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/reports/summary */
    public function summary(\stdClass $payload, array $args): void
    {
        // Total enrolled students per stream
        $naturalCount = $this->db->users->countDocuments(['stream' => 'natural', 'isActive' => true]);
        $socialCount  = $this->db->users->countDocuments(['stream' => 'social',  'isActive' => true]);

        // Enrollment totals
        $totalEnrollments = $this->db->enrollments->countDocuments([]);
        $completed        = $this->db->enrollments->countDocuments([
            'completedAt' => ['$ne' => null],
        ]);

        // Average quiz score across all attempts
        $avgScore = 0.0;
        $cursor   = $this->db->quizAttempts->aggregate([
            ['$group' => [
                '_id'     => null,
                'avgPct'  => ['$avg' => [
                    '$cond' => [
                        'if'   => ['$gt' => ['$total', 0]],
                        'then' => ['$multiply' => [['$divide' => ['$score', '$total']], 100]],
                        'else' => 0,
                    ],
                ]],
            ]],
        ]);
        foreach ($cursor as $row) {
            $avgScore = round((float) ($row['avgPct'] ?? 0), 1);
        }

        // Top 5 most enrolled courses
        $topCourses = $this->db->enrollments->aggregate([
            ['$group' => ['_id' => '$courseId', 'count' => ['$sum' => 1]]],
            ['$sort'  => ['count' => -1]],
            ['$limit' => 5],
        ]);

        $topList = [];
        foreach ($topCourses as $row) {
            $course    = $this->db->courses->findOne(
                ['_id' => $row['_id']],
                ['projection' => ['title' => 1]]
            );
            $topList[] = [
                'courseId'    => (string) $row['_id'],
                'courseTitle' => $course['title'] ?? '',
                'count'       => (int) $row['count'],
            ];
        }

        Response::json(true, [
            'activeUsers'        => ['natural' => $naturalCount, 'social' => $socialCount],
            'totalEnrollments'   => $totalEnrollments,
            'completedCourses'   => $completed,
            'completionRate'     => $totalEnrollments > 0
                ? round($completed / $totalEnrollments * 100, 1)
                : 0,
            'avgQuizScorePct'    => $avgScore,
            'topEnrolledCourses' => $topList,
        ], 'OK');
    }
}
