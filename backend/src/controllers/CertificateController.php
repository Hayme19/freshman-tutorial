<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\ObjectIdHelper;

/**
 * Certificates — per DESIGN §6 mod12, §12.
 *
 * GET /api/certificates/{id}   — owner (student) or admin
 * GET /api/certificates        — student's own certificates
 */
final class CertificateController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** GET /api/certificates */
    public function listMine(\stdClass $payload, array $args): void
    {
        $studentId = ObjectIdHelper::requireFromString((string) ($payload->sub ?? ''));
        $certs     = $this->db->certificates
            ->find(['studentId' => $studentId], ['sort' => ['issuedAt' => -1]])
            ->toArray();
        Response::json(true, $certs, 'OK');
    }

    /** GET /api/certificates/{id} */
    public function get(\stdClass $payload, array $args): void
    {
        $oid  = ObjectIdHelper::requireFromString($args[0] ?? '');
        $cert = $this->db->certificates->findOne(['_id' => $oid]);
        if ($cert === null) Response::json(false, null, 'Certificate not found', 404);

        // Only the owner or admin may view
        if ($payload->role !== 'admin' &&
            (string) $cert['studentId'] !== (string) ($payload->sub ?? '')) {
            Response::json(false, null, 'Forbidden', 403);
        }

        Response::json(true, $cert, 'OK');
    }
}
