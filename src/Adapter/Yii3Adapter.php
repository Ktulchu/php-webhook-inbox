<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Adapter;

use InvalidArgumentException;
use Ktulchu\WebhookInbox\ConnectionInterface;
use LogicException;
use PDO;
use Yiisoft\Db\Connection\ConnectionInterface as YiiConnection;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;

/** Optional Yii3 adapter: SQL and transactions stay on the application's Yii DB connection. */
final class Yii3Adapter implements ConnectionInterface
{
    private ?TransactionInterface $ownedTransaction = null;

    public function __construct(private readonly YiiConnection $connection)
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException('Only PostgreSQL is supported');
        }
    }

    public function inTransaction(): bool
    {
        if ($this->connection->getTransaction() !== null) {
            return true;
        }

        // Guard native PDO transactions where the Yii3 connection exposes its PDO.
        // Never issue SQL or open another connection to check transaction state.
        if ($this->connection instanceof PdoConnectionInterface) {
            $pdo = $this->connection->getPdo();
            return $pdo instanceof PDO && $pdo->inTransaction();
        }

        return false;
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
        // Yii3 commands execute against this injected connection; no replica
        // routing or query-result cache is applied to raw SQL commands.
        return $this->connection->createCommand($sql, $this->bindParams($params))->queryOne();
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->connection->createCommand($sql, $this->bindParams($params))->execute();
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    private function bindParams(array $params): array
    {
        // Inbox contract uses names without ':'; Yii3 PDO binds named placeholders.
        $bound = [];
        foreach ($params as $name => $value) {
            $bound[':' . ltrim($name, ':')] = $value;
        }
        return $bound;
    }
}
