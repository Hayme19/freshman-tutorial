<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Reads and decodes the JSON request body sent by the frontend.
 */
final class Input
{
    /**
     * @return array<string,mixed>
     */
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }
}
