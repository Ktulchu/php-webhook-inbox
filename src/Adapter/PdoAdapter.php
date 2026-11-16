<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Adapter;

use Ktulchu\WebhookInbox\ConnectionInterface;
use LogicException;
use PDO;

/** Uses an application-provided PostgreSQL PDO; never opens a connection. */
final class PdoAdapter implements ConnectionInterface
{
    public function __construct(private readonly PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \InvalidArgumentException('Only PostgreSQL is supported');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction()) {
            throw new LogicException('A worker requires an idle connection');
        }
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }
}
