<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Adapter;

use Ktulchu\WebhookInbox\ConnectionInterface;
use LogicException;
use yii\db\Connection;
use yii\db\Transaction;

/** Optional Yii2 adapter: Yii owns the connection and transaction bookkeeping. */
final class Yii2Adapter implements ConnectionInterface
{
    private ?Transaction $ownedTransaction = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function inTransaction(): bool
    {
        // An external transaction is not ours to commit. Also guard consumers
        // that bypass Yii's transaction API by opening one directly on PDO.
        return $this->connection->getTransaction() !== null
            || ($this->connection->pdo instanceof \PDO && $this->connection->pdo->inTransaction());
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction()) {
            throw new LogicException('A worker requires an idle connection');
        }
        $this->ownedTransaction = $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->ownedTransaction === null) {
            throw new LogicException('No adapter-owned transaction');
        }
        $this->ownedTransaction->commit();
        $this->ownedTransaction = null;
    }

    public function rollBack(): void
    {
        if ($this->ownedTransaction === null) {
            throw new LogicException('No adapter-owned transaction');
        }
        $this->ownedTransaction->rollBack();
        $this->ownedTransaction = null;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->connection->createCommand($sql, $params)->queryOne();
        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->connection->createCommand($sql, $params)->execute();
    }
}
