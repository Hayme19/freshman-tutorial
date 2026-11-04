<?php

declare(strict_types=1);

namespace App\Config;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database as MongoDatabase;

/**
 * Singleton MongoDB connection.
 *
 * Reads MONGODB_URI and DB_NAME from the environment (populated by vlucas/phpdotenv
 * in public/index.php and seed/load.php). Exposes the active database via getDb()
 * and proxies collection access via __get() so callers may use Database::get()->users.
 */
final class Database
{
    private static ?Database $instance = null;

    private ?Client $client = null;
    private ?MongoDatabase $db = null;
    private ?\Throwable $error = null;

    private function __construct()
    {
        $uri = $_ENV['MONGODB_URI'] ?? $_SERVER['MONGODB_URI'] ?? getenv('MONGODB_URI');
        if (!is_string($uri) || $uri === '') {
            $this->error = new \RuntimeException('MONGODB_URI is not configured');
            return;
        }

        $name = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? getenv('DB_NAME');
        if (!is_string($name) || $name === '') {
            $name = 'tutorialdb';
        }

        try {
            $this->client = new Client($uri);
            $this->db = $this->client->selectDatabase($name);
        } catch (\Throwable $e) {
            // Connection errors are lazy with the driver, but a malformed URI throws here.
            $this->client = null;
            $this->db = null;
            $this->error = $e;
        }
    }

    public static function get(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getDb(): ?MongoDatabase
    {
        return $this->db;
    }

    public function getError(): ?\Throwable
    {
        return $this->error;
    }

    /**
     * Allow collection access such as Database::get()->courses->find(...).
     */
    public function __get(string $name): ?Collection
    {
        return $this->db !== null ? $this->db->{$name} : null;
    }
}
