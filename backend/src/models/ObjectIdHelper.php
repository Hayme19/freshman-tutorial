<?php

declare(strict_types=1);

namespace App\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * Converts MongoDB BSON documents/arrays into plain PHP arrays that
 * json_encode() can serialise. ObjectId → ['$oid' => string],
 * UTCDateTime → ISO-8601 string.
 *
 * Also provides a safe ObjectId constructor that returns null instead
 * of throwing when the id string is invalid.
 */
final class ObjectIdHelper
{
    /**
     * Recursively convert $data (a BSON document, array, or scalar) into a
     * plain array/scalar ready for json_encode().
     *
     * @param mixed $data
     * @return mixed
     */
    public static function serialize(mixed $data): mixed
    {
        if ($data instanceof ObjectId) {
            return ['$oid' => (string) $data];
        }

        if ($data instanceof UTCDateTime) {
            return $data->toDateTime()->format(\DateTime::ATOM);
        }

        if ($data instanceof BSONDocument || $data instanceof \stdClass) {
            $out = [];
            foreach ((array) $data as $k => $v) {
                $out[$k] = self::serialize($v);
            }
            return $out;
        }

        if ($data instanceof BSONArray || is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = self::serialize($v);
            }
            return $out;
        }

        return $data;
    }

    /**
     * Safely cast a string to an ObjectId.
     * Returns null (and optionally sends a 400 response via $respondOnError)
     * if $idStr is not a valid 24-hex-character id.
     */
    public static function fromString(string $idStr): ?ObjectId
    {
        try {
            return new ObjectId($idStr);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convenience: cast or die with a 400 JSON response.
     * Call from controllers where a bad id should terminate the request.
     */
    public static function requireFromString(string $idStr): ObjectId
    {
        $oid = self::fromString($idStr);
        if ($oid === null) {
            \App\Helpers\Response::json(false, null, 'Invalid id format', 400);
        }
        return $oid; // @phpstan-ignore-line (Response::json exits)
    }
}
