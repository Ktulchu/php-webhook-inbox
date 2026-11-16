<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Tests;

use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\DriverManager;
use Ktulchu\WebhookInbox\Adapter\DoctrineAdapter;
use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\Adapter\Yii2Adapter;
use Ktulchu\WebhookInbox\ConnectionInterface;
use Ktulchu\WebhookInbox\EventHandler;
use Ktulchu\WebhookInbox\Example\Database;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Receiver;
use Ktulchu\WebhookInbox\Worker;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use yii\db\Connection as YiiConnection;
use yii\caching\ArrayCache;

final class AdapterIntegrationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function adapters(): iterable
    {
        yield 'PDO' => ['pdo'];
        yield 'Yii2' => ['yii2'];
        yield 'Doctrine DBAL 4' => ['doctrine'];
    }

    #[DataProvider('adapters')]
    public function testTransactionSavepointAndSqlErrorRecovery(string $kind): void
    {
        $db = $this->adapter($kind);
        $this->reset();

        self::assertFalse($db->inTransaction());
        $db->beginTransaction();
        self::assertTrue($db->inTransaction());
        $db->execute("INSERT INTO demo_effects (source,event_id,amount)
            VALUES ('contract','rollback',1)");
        $db->rollBack();
        self::assertFalse($db->inTransaction());
        self::assertSame(null, $db->fetchOne(
            "SELECT event_id FROM demo_effects WHERE event_id='rollback'"
        ));

        $db->beginTransaction();
        $db->execute('SAVEPOINT test_contract');
        try {
            $db->execute("INSERT INTO demo_effects (source,event_id,amount)
                VALUES ('contract','invalid','not-an-integer')");
            self::fail('Expected PostgreSQL to reject invalid integer');
        } catch (Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }
        $db->execute('ROLLBACK TO SAVEPOINT test_contract');
        $db->execute("INSERT INTO demo_effects (source,event_id,amount)
            VALUES ('contract','committed',2)");
        $db->commit();

        self::assertFalse($db->inTransaction());
        self::assertNotNull($db->fetchOne(
            "SELECT event_id FROM demo_effects WHERE event_id='committed'"
        ));
    }

    #[DataProvider('adapters')]
    public function testWorkerRejectsExistingTransactionWithoutChangingIt(string $kind): void
    {
        $db = $this->adapter($kind);
        $this->reset();
        (new Inbox($db))->receive('demo', 'external', '{"id":"external","amount":1}');
        $handler = new class implements EventHandler {
            public function handle(ConnectionInterface $db, array $event): void
            {
                throw new \RuntimeException('Must never execute');
            }
        };

        $db->beginTransaction();
        try {
            (new Worker($db, $handler))->runOnce();
            self::fail('Worker should reject existing transaction');
        } catch (LogicException $e) {
            self::assertStringContainsString('existing transaction', $e->getMessage());
            self::assertTrue($db->inTransaction(), 'Must not commit or roll back caller transaction');
        } finally {
            $db->rollBack();
        }
        self::assertSame('pending', $db->fetchOne(
            "SELECT status FROM inbox_events WHERE event_id='external'"
        )['status']);
    }

    #[DataProvider('adapters')]
    public function testWorkerPassesSameAdapterAndSessionToHandler(string $kind): void
    {
        $db = $this->adapter($kind);
        $this->reset();

        $originalPid = (string) $db->fetchOne('SELECT pg_backend_pid() AS pid')['pid'];
        (new Inbox($db))->receive('demo', 'session-test', '{"id":"session-test","amount":1}');
        $handler = new class($db) implements EventHandler {
            public bool $sameInstance = false;
            public ?string $sessionPid = null;

            public function __construct(private readonly ConnectionInterface $expected)
            {
            }

            public function handle(ConnectionInterface $db, array $event): void
            {
                $this->sameInstance = ($db === $this->expected);
                $this->sessionPid = (string) $db->fetchOne('SELECT pg_backend_pid() AS pid')['pid'];
                $db->execute(
                    'INSERT INTO demo_effects (source,event_id,amount) VALUES (:s,:e,:amount)',
                    ['s' => $event['source'], 'e' => $event['event_id'], 'amount' => 1]
                );
            }
        };

        self::assertTrue((new Worker($db, $handler))->runOnce());
        self::assertTrue($handler->sameInstance);
        self::assertSame($originalPid, $handler->sessionPid);
        self::assertFalse($db->inTransaction());
        self::assertSame(1, (int) $db->fetchOne(
            "SELECT count(*) AS count FROM demo_effects WHERE event_id='session-test'"
        )['count']);
        self::assertSame('done', $db->fetchOne(
            "SELECT status FROM inbox_events WHERE event_id='session-test'"
        )['status']);
    }


    #[DataProvider('adapters')]
    public function testHandlerSqlErrorIsCountedAsRetryAndFullyRolledBack(string $kind): void
    {
        $db = $this->adapter($kind);
        $this->reset();
        (new Inbox($db))->receive('demo', 'sql-failure', '{"id":"sql-failure","amount":1}');
        $handler = new class implements EventHandler {
            public function handle(ConnectionInterface $db, array $event): void
            {
                $db->execute(
                    'INSERT INTO demo_effects (source,event_id,amount) VALUES (:s,:e,1)',
                    ['s' => $event['source'], 'e' => $event['event_id']]
                );
                $db->execute("INSERT INTO demo_effects (source,event_id,amount)
                    VALUES ('demo','broken','not-an-integer')");
            }
        };

        self::assertTrue((new Worker($db, $handler, 2))->runOnce());
        self::assertFalse($db->inTransaction());
        self::assertSame('pending', $db->fetchOne(
            "SELECT status FROM inbox_events WHERE event_id='sql-failure'"
        )['status']);
        self::assertSame(1, (int) $db->fetchOne(
            "SELECT attempts FROM inbox_events WHERE event_id='sql-failure'"
        )['attempts']);
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT count(*) AS count FROM demo_effects'
        )['count']);
    }

    #[DataProvider('adapters')]
    public function testSkipLockedWithEachAdapter(string $kind): void
    {
        $db = $this->adapter($kind);
        $this->reset();
        (new Inbox($db))->receive('demo', 'lock-test', '{"id":"lock-test","amount":1}');
        $other = Database::connect();
        $other->beginTransaction();
        try {
            $other->query("SELECT id FROM inbox_events WHERE event_id='lock-test' FOR UPDATE")->fetch();
            $handler = new class implements EventHandler {
                public function handle(ConnectionInterface $db, array $event): void
                {
                    $db->execute(
                        'INSERT INTO demo_effects (source,event_id,amount) VALUES (:s,:e,1)',
                        ['s' => $event['source'], 'e' => $event['event_id']]
                    );
                }
            };
            self::assertFalse((new Worker($db, $handler))->runOnce());
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT count(*) AS count FROM demo_effects'
            )['count']);
        } finally {
            $other->rollBack();
        }
        self::assertTrue((new Worker($db, $handler))->runOnce());
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT count(*) AS count FROM demo_effects'
        )['count']);
    }

    #[DataProvider('adapters')]
    public function testInboxRejectsExternalTransactionBeforeInsertWithoutChangingIt(string $kind): void
    {
        $db = $this->adapter($kind);
        $this->reset();
        $inbox = new Inbox($db);
        $body = '{"id":"outer-transaction","amount":1}';

        $db->beginTransaction();
        try {
            try {
                $inbox->receive('demo', 'outer-transaction', $body);
                self::fail('Inbox must not return accepted inside a caller transaction');
            } catch (LogicException $e) {
                self::assertStringContainsString('existing transaction', $e->getMessage());
            }

            self::assertTrue($db->inTransaction(), 'Inbox must not close the caller transaction');
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT count(*) AS count FROM inbox_events'
            )['count'], 'Reject admission before writing to inbox_events');

            // The external transaction is still usable and remains caller-owned.
            $db->execute(
                "INSERT INTO demo_effects (source,event_id,amount) VALUES ('caller','uncommitted',1)"
            );
            self::assertTrue($db->inTransaction());
        } finally {
            $db->rollBack();
        }

        self::assertFalse($db->inTransaction());
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT count(*) AS count FROM inbox_events'
        )['count']);
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT count(*) AS count FROM demo_effects'
        )['count']);

        self::assertSame('accepted', $inbox->receive('demo', 'outer-transaction', $body));
        self::assertSame('duplicate', $inbox->receive('demo', 'outer-transaction', $body));
        self::assertSame(1, (int) Database::connect()->query(
            'SELECT count(*) FROM inbox_events'
        )->fetchColumn(), 'Successful admission is committed and visible to another connection');
    }

    #[DataProvider('adapters')]
    public function testReceiverCannotReturnHttp202InsideExternalTransaction(string $kind): void
    {
        $db = $this->adapter($kind);
        $this->reset();
        $receiver = new Receiver(new Inbox($db), 'demo', 'test-signing-secret-for-receiver');
        $body = '{"id":"receiver-outer","amount":5}';
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac(
            'sha256',
            $timestamp . '.' . $body,
            'test-signing-secret-for-receiver'
        );

        $db->beginTransaction();
        try {
            try {
                $receiver->handle('POST', $body, $timestamp, $signature);
                self::fail('Receiver must not return HTTP 202 for an uncommitted event');
            } catch (LogicException $e) {
                self::assertStringContainsString('existing transaction', $e->getMessage());
            }
            self::assertTrue($db->inTransaction());
            self::assertSame(0, (int) $db->fetchOne(
                'SELECT count(*) AS count FROM inbox_events'
            )['count']);
        } finally {
            $db->rollBack();
        }

        self::assertFalse($db->inTransaction());
        self::assertSame(0, (int) Database::connect()->query(
            'SELECT count(*) FROM inbox_events'
        )->fetchColumn());
        self::assertSame(202, $receiver->handle('POST', $body, $timestamp, $signature)['status']);
        self::assertSame(1, (int) Database::connect()->query(
            'SELECT count(*) FROM inbox_events'
        )->fetchColumn());
    }

    public function testYii2MasterRoutingBypassesStaleReadOnlySlaveAndQueryCache(): void
    {
        $this->reset();
        $yii = $this->yiiConnection();
        $yii->slaves = [[
            'dsn' => $yii->dsn,
            'username' => $yii->username,
            'password' => $yii->password,
            'charset' => 'utf8',
        ]];
        // Isolated PHPUnit run has no Yii::$app service locator.
        $yii->serverStatusCache = null;
        $yii->enableQueryCache = true;
        $yii->queryCache = new ArrayCache();

        $master = $yii->getMasterPdo();
        $slave = $yii->getSlavePdo(false);
        self::assertInstanceOf(PDO::class, $slave);
        $masterPid = (string) $master->query('SELECT pg_backend_pid()')->fetchColumn();
        $slavePid = (string) $slave->query('SELECT pg_backend_pid()')->fetchColumn();
        self::assertNotSame($masterPid, $slavePid, 'This test requires distinct real PostgreSQL sessions');
        $originalSlavesEnabled = $yii->enableSlaves;

        // Simulate replica lag using a different PostgreSQL session with a
        // read-only REPEATABLE READ snapshot from before event admission.
        $slave->beginTransaction();
        try {
            $slave->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            self::assertSame(0, (int) $slave->query('SELECT count(*) FROM inbox_events')->fetchColumn());

            $adapter = new Yii2Adapter($yii);
            $inbox = new Inbox($adapter);
            $body = '{"id":"master-only","amount":14}';

            $yii->cache(function (YiiConnection $db) use ($adapter, $inbox, $body, $masterPid): void {
                self::assertSame($masterPid, (string) $adapter->fetchOne(
                    'SELECT pg_backend_pid() AS pid'
                )['pid'], 'fetchOne must use the master, not a slave');
                self::assertSame(0, (int) $adapter->fetchOne(
                    'SELECT count(*) AS n FROM inbox_events'
                )['n']);

                self::assertSame('accepted', $inbox->receive('demo', 'master-only', $body));
                self::assertSame('duplicate', $inbox->receive('demo', 'master-only', $body));
                self::assertSame(1, (int) $adapter->fetchOne(
                    'SELECT count(*) AS n FROM inbox_events'
                )['n'], 'Critical queries must bypass an enabled query cache');

                self::assertSame($masterPid, (string) $adapter->fetchOne(
                    'SELECT pg_backend_pid() AS pid'
                )['pid']);
            }, 3600);

            self::assertSame(0, (int) $yii->createCommand(
                'SELECT count(*) FROM inbox_events'
            )->queryScalar(), 'Ordinary Yii2 read stays on the stale slave');
            self::assertSame(1, (int) $master->query(
                'SELECT count(*) FROM inbox_events'
            )->fetchColumn(), 'Committed webhook must live on master');
            self::assertSame($originalSlavesEnabled, $yii->enableSlaves);

            $worker = new Worker($adapter, new \Ktulchu\WebhookInbox\Example\DemoHandler());
            self::assertTrue($worker->runOnce());
            self::assertFalse($worker->runOnce());
            self::assertSame(1, (int) $master->query(
                'SELECT count(*) FROM demo_effects'
            )->fetchColumn(), 'A duplicate delivery must not produce a second business effect');
        } finally {
            $slave->rollBack();
        }

        self::assertSame($originalSlavesEnabled, $yii->enableSlaves);
        self::assertSame($slavePid, (string) $yii->createCommand(
            'SELECT pg_backend_pid()'
        )->queryScalar(), 'Normal read routing must be restored after adapter calls');
    }

    public function testNativeYii2TransactionCannotAcknowledgeWebhook(): void
    {
        $yii = $this->yiiConnection();
        $db = new Yii2Adapter($yii);
        $this->reset();
        $inbox = new Inbox($db);
        $body = '{"id":"native-yii","amount":3}';
        $timestamp = (string) time();
        $secret = 'a-secret-for-native-framework-test';
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $receiver = new Receiver($inbox, 'demo', $secret);

        $transaction = $yii->beginTransaction(); // explicitly NOT through the adapter
        $level = $transaction->level;
        try {
            try {
                $inbox->receive('demo', 'native-yii', $body);
                self::fail('Expected fail-fast inside Yii2-owned transaction');
            } catch (LogicException $e) {
                self::assertStringContainsString('existing transaction', $e->getMessage());
            }
            try {
                $receiver->handle('POST', $body, $timestamp, $signature);
                self::fail('No HTTP 202 may be returned for an uncommitted event');
            } catch (LogicException $e) {
                self::assertStringContainsString('existing transaction', $e->getMessage());
            }
            self::assertSame($level, $transaction->level);
            self::assertTrue($transaction->isActive);
            self::assertTrue($yii->pdo->inTransaction());
            self::assertSame(0, (int) $yii->createCommand(
                'SELECT count(*) FROM inbox_events'
            )->queryScalar());
            // The caller can still execute SQL in its own transaction.
            self::assertSame(1, (int) $yii->createCommand('SELECT 1')->queryScalar());
        } finally {
            $transaction->rollBack();
        }

        self::assertFalse($db->inTransaction());
        self::assertSame(0, (int) Database::connect()->query(
            'SELECT count(*) FROM inbox_events'
        )->fetchColumn());
        self::assertSame(202, $receiver->handle('POST', $body, $timestamp, $signature)['status']);
        self::assertSame(1, (int) Database::connect()->query(
            'SELECT count(*) FROM inbox_events'
        )->fetchColumn());
    }

    public function testNativeDoctrineTransactionCannotAcknowledgeWebhook(): void
    {
        $connection = $this->doctrineConnection();
        $db = new DoctrineAdapter($connection);
        $this->reset();
        $inbox = new Inbox($db);
        $body = '{"id":"native-doctrine","amount":4}';

        $connection->beginTransaction(); // explicitly NOT through the adapter
        $level = $connection->getTransactionNestingLevel();
        try {
            try {
                $inbox->receive('demo', 'native-doctrine', $body);
                self::fail('Expected fail-fast inside DBAL-owned transaction');
            } catch (LogicException $e) {
                self::assertStringContainsString('existing transaction', $e->getMessage());
            }
            self::assertSame($level, $connection->getTransactionNestingLevel());
            self::assertTrue($db->inTransaction());
            self::assertSame(0, (int) $connection->fetchOne(
                'SELECT count(*) FROM inbox_events'
            ));
            self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
        } finally {
            $connection->rollBack();
        }

        self::assertFalse($db->inTransaction());
        self::assertSame(0, (int) Database::connect()->query(
            'SELECT count(*) FROM inbox_events'
        )->fetchColumn());
        self::assertSame('accepted', $inbox->receive('demo', 'native-doctrine', $body));
        self::assertSame(1, (int) Database::connect()->query(
            'SELECT count(*) FROM inbox_events'
        )->fetchColumn());
    }

    private function reset(): void
    {
        Database::connect()->exec('TRUNCATE TABLE demo_effects, inbox_events RESTART IDENTITY');
    }

    private function adapter(string $kind): ConnectionInterface
    {
        if ($kind === 'pdo') {
            return new PdoAdapter(Database::connect());
        }

        if ($kind === 'yii2') {
            return new Yii2Adapter($this->yiiConnection());
        }

        return new DoctrineAdapter($this->doctrineConnection());
    }

    private function yiiConnection(): YiiConnection
    {
        if (!class_exists(YiiConnection::class)) {
            self::markTestSkipped('Yii2 not installed; run framework-adapters CI job');
        }
        if (!class_exists(\Yii::class, false)) {
            require_once dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
        }
        $connection = new YiiConnection([
            'dsn' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: '127.0.0.1',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'webhooks',
            ),
            'username' => getenv('DB_USER') ?: 'webhooks',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8',
        ]);
        $connection->open();
        return $connection;
    }

    private function doctrineConnection(): DoctrineConnection
    {
        if (!class_exists(DriverManager::class)) {
            self::markTestSkipped('Doctrine DBAL not installed; run framework-adapters CI job');
        }

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 5432),
            'dbname' => getenv('DB_NAME') ?: 'webhooks',
            'user' => getenv('DB_USER') ?: 'webhooks',
            'password' => getenv('DB_PASSWORD') ?: '',
        ]);
    }
}
