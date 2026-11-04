<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;
use MongoDB\BSON\UTCDateTime;

/**
 * Discussion forum — per DESIGN §12.
 *
 * GET  /api/forum            — logged-in (?courseId= filter)
 * POST /api/forum            — logged-in
 * POST /api/forum/{id}/reply — logged-in
 */
final class ForumController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/forum?courseId= */
    public function list(\stdClass $payload, array $args): void
    {
        $filter   = [];
        $courseId = $_GET['courseId'] ?? null;
        if ($courseId !== null) {
            $oid = ObjectIdHelper::requireFromString($courseId);
            $filter['courseId'] = $oid;
        }

        $posts = $this->db->forumPosts
            ->find($filter, ['sort' => ['createdAt' => -1]])
            ->toArray();

        Response::json(true, $posts, 'OK');
    }

    /** POST /api/forum */
    public function create(\stdClass $payload, array $args): void
    {
        $body     = $this->body();
        $courseId = ObjectIdHelper::requireFromString((string) ($body['courseId'] ?? ''));
        $authorId = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));
        $title    = trim((string) ($body['title'] ?? ''));
        $bodyText = trim((string) ($body['body'] ?? ''));

        if ($title === '' || $bodyText === '') {
            Response::json(false, null, 'title and body are required', 422);
        }

        $doc = [
            'courseId'  => $courseId,
            'authorId'  => $authorId,
            'title'     => $title,
            'body'      => $bodyText,
            'replies'   => [],
            'createdAt' => new UTCDateTime(),
        ];

        $result   = $this->db->forumPosts->insertOne($doc);
        $doc['_id'] = $result->getInsertedId();
        Response::json(true, ObjectIdHelper::serialize($doc), 'Post created', 201);
    }

    /** POST /api/forum/{id}/reply */
    public function reply(\stdClass $payload, array $args): void
    {
        $postId   = ObjectIdHelper::requireFromString($args[0] ?? '');
        $authorId = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));

        $post = $this->db->forumPosts->findOne(['_id' => $postId]);
        if ($post === null) Response::json(false, null, 'Post not found', 404);

        $body     = $this->body();
        $bodyText = trim((string) ($body['body'] ?? ''));
        if ($bodyText === '') Response::json(false, null, 'body is required', 422);

        $reply = [
            'authorId'  => $authorId,
            'body'      => $bodyText,
            'createdAt' => new UTCDateTime(),
        ];

        $this->db->forumPosts->updateOne(
            ['_id' => $postId],
            ['$push' => ['replies' => $reply]]
        );

        Response::json(true, ObjectIdHelper::serialize($reply), 'Reply added', 201);
    }

    private function body(): array
    {
        $raw     = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
