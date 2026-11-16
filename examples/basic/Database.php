<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Example;

use PDO;

/** Local demo only: applications construct their own connections. */
final class Database
{
    public static function connect(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'webhooks';
        $user = getenv('DB_USER') ?: 'webhooks';
        $password = getenv('DB_PASSWORD') ?: '';

        return new PDO(
            "pgsql:host={$host};port={$port};dbname={$name}",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }
}
