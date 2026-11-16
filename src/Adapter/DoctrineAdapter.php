<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Adapter;

use Doctrine\DBAL\Connection;
use Ktulchu\WebhookInbox\ConnectionInterface;
use LogicException;

/** Optional Doctrine DBAL adapter: never bypass DBAL transaction tracking. */
final class DoctrineAdapter implements ConnectionInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function inTransaction(): bool
    {
        return $this->connection->getTransactionNestingLevel() > 0;
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction()) {
            throw new LogicException('A worker requires an idle connection');
        }
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->connection->fetchAssociative($sql, $params);
        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->connection->executeStatement($sql, $params);
    }
}
