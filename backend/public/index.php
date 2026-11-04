<?php

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Middleware\Cors;
use App\Middleware\AuthMiddleware;
use App\Helpers\Response;
use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Controllers\CourseController;
use App\Controllers\LessonController;
use App\Controllers\EnrollmentController;
use App\Controllers\ProgressController;
use App\Controllers\QuizController;
use App\Controllers\SubmissionController;
use App\Controllers\ForumController;
use App\Controllers\AnnouncementController;
use App\Controllers\AdminController;
use App\Controllers\ReportController;
use App\Controllers\CertificateController;
use App\Controllers\FitnessLogController;
use App\Controllers\NotificationController;

// Load .env when running locally; on Render env vars are already in the environment.
Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

// ── CORS (must run before anything else) ───────────────────────────────────
Cors::handle();

// ── Request info ───────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
// Strip query string and normalise trailing slash
$rawUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = rtrim((string) $rawUri, '/') ?: '/';

// ── Route table ────────────────────────────────────────────────────────────
// [ HTTP_METHOD, regex, [Controller, method], roles|null ]
// roles = null → no auth required
// roles = ['any'] → any authenticated user
// roles = ['student'] / ['instructor','admin'] / etc. → role-checked
//
// Capture groups in regex are passed as $args to the controller method.
// ObjectId casting happens inside the controllers (try/catch → 400).

$routes = [

    // ── Auth ──────────────────────────────────────────────────────────────
    ['POST',   '#^/api/auth/register$#',                 [AuthController::class,         'register'],      null],
    ['POST',   '#^/api/auth/login$#',                    [AuthController::class,         'login'],         null],
    ['GET',    '#^/api/auth/me$#',                       [AuthController::class,         'me'],            ['any']],

    // ── Users / Profile ───────────────────────────────────────────────────
    ['PUT',    '#^/api/users/me$#',                      [UserController::class,         'updateMe'],      ['any']],

    // ── Courses ───────────────────────────────────────────────────────────
    ['GET',    '#^/api/courses$#',                       [CourseController::class,       'list'],          null],
    ['GET',    '#^/api/courses/([a-f0-9]{24})$#',        [CourseController::class,       'get'],           null],
    ['POST',   '#^/api/courses$#',                       [CourseController::class,       'create'],        ['instructor', 'admin']],
    ['PUT',    '#^/api/courses/([a-f0-9]{24})$#',        [CourseController::class,       'update'],        ['instructor', 'admin']],
    ['DELETE', '#^/api/courses/([a-f0-9]{24})$#',        [CourseController::class,       'delete'],        ['instructor', 'admin']],

    // ── Lessons ───────────────────────────────────────────────────────────
    ['GET',    '#^/api/courses/([a-f0-9]{24})/lessons$#',[LessonController::class,       'listByCourse'],  null],
    ['POST',   '#^/api/lessons$#',                       [LessonController::class,       'create'],        ['instructor', 'admin']],
    ['PUT',    '#^/api/lessons/([a-f0-9]{24})$#',        [LessonController::class,       'update'],        ['instructor', 'admin']],
    ['DELETE', '#^/api/lessons/([a-f0-9]{24})$#',        [LessonController::class,       'delete'],        ['instructor', 'admin']],

    // ── Enrollments ───────────────────────────────────────────────────────
    ['POST',   '#^/api/enrollments$#',                   [EnrollmentController::class,   'create'],        ['student']],

    // ── Progress ──────────────────────────────────────────────────────────
    ['POST',   '#^/api/progress/complete-lesson$#',      [ProgressController::class,     'completeLesson'],['student']],
    ['GET',    '#^/api/progress/me$#',                   [ProgressController::class,     'getMyProgress'], ['any']],

    // ── Quizzes ───────────────────────────────────────────────────────────
    ['GET',    '#^/api/quizzes/([a-f0-9]{24})$#',        [QuizController::class,         'get'],           ['any']],
    ['POST',   '#^/api/quizzes/([a-f0-9]{24})/attempt$#',[QuizController::class,         'attempt'],       ['student']],
    ['POST',   '#^/api/quizzes$#',                       [QuizController::class,         'create'],        ['instructor', 'admin']],
    ['PUT',    '#^/api/quizzes/([a-f0-9]{24})$#',        [QuizController::class,         'update'],        ['instructor', 'admin']],

    // ── Submissions / Exercises ───────────────────────────────────────────
    ['POST',   '#^/api/submissions$#',                   [SubmissionController::class,   'create'],        ['student']],
    ['PUT',    '#^/api/submissions/([a-f0-9]{24})/grade$#',[SubmissionController::class, 'grade'],         ['instructor', 'tutor', 'admin']],

    // ── Forum ─────────────────────────────────────────────────────────────
    ['GET',    '#^/api/forum$#',                         [ForumController::class,        'list'],          ['any']],
    ['POST',   '#^/api/forum$#',                         [ForumController::class,        'create'],        ['any']],
    ['POST',   '#^/api/forum/([a-f0-9]{24})/reply$#',   [ForumController::class,        'reply'],         ['any']],

    // ── Announcements ─────────────────────────────────────────────────────
    ['GET',    '#^/api/announcements$#',                 [AnnouncementController::class, 'list'],          ['any']],
    ['POST',   '#^/api/announcements$#',                 [AnnouncementController::class, 'create'],        ['instructor', 'admin']],

    // ── Admin ─────────────────────────────────────────────────────────────
    ['GET',    '#^/api/admin/users$#',                   [AdminController::class,        'listUsers'],     ['admin']],
    ['PUT',    '#^/api/admin/users/([a-f0-9]{24})/role$#',[AdminController::class,       'updateRole'],    ['admin']],

    // ── Reports ───────────────────────────────────────────────────────────
    ['GET',    '#^/api/reports/summary$#',               [ReportController::class,       'summary'],       ['admin', 'instructor']],

    // ── Certificates ──────────────────────────────────────────────────────
    ['GET',    '#^/api/certificates$#',                  [CertificateController::class,  'listMine'],      ['any']],
    ['GET',    '#^/api/certificates/([a-f0-9]{24})$#',   [CertificateController::class,  'get'],           ['any']],

    // ── Fitness logs ──────────────────────────────────────────────────────
    ['GET',    '#^/api/fitness-logs$#',                  [FitnessLogController::class,   'list'],          ['any']],
    ['POST',   '#^/api/fitness-logs$#',                  [FitnessLogController::class,   'create'],        ['any']],
    ['PUT',    '#^/api/fitness-logs/([a-f0-9]{24})$#',   [FitnessLogController::class,   'update'],        ['any']],
    ['DELETE', '#^/api/fitness-logs/([a-f0-9]{24})$#',   [FitnessLogController::class,   'delete'],        ['any']],

    // ── Notifications ─────────────────────────────────────────────────────
    ['GET',    '#^/api/notifications$#',                 [NotificationController::class, 'list'],          ['any']],
    ['PUT',    '#^/api/notifications/([a-f0-9]{24})/read$#',[NotificationController::class,'markRead'],    ['any']],
];

// ── Dispatch ───────────────────────────────────────────────────────────────
foreach ($routes as [$m, $pattern, $handler, $roles]) {
    if ($m === $method && preg_match($pattern, $path, $matches)) {
        $args    = array_slice($matches, 1);         // captured {id} groups
        $payload = $roles !== null
            ? AuthMiddleware::require($roles)        // exits on 401/403
            : null;
        $controller = new $handler[0]();
        $controller->{$handler[1]}($payload, $args);
        exit;
    }
}

Response::json(false, null, 'Not found', 404);
