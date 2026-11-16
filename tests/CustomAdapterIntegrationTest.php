<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Tests;

use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\ConnectionInterface;
use Ktulchu\WebhookInbox\EventHandler;
use Ktulchu\WebhookInbox\Example\Database;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Worker;
use PHPUnit\Framework\TestCase;

final class CustomAdapterIntegrationTest extends TestCase
{
    public function testApplicationCanSupplyItsOwnConnectionImplementation(): void
    {
        $pdo = Database::connect();
        $pdo->exec('TRUNCATE TABLE demo_effects, inbox_events RESTART IDENTITY');
        $adapter = new class(new PdoAdapter($pdo)) implements ConnectionInterface {
            public function __construct(private readonly PdoAdapter $delegate)
            {
            }

            public function inTransaction(): bool
            {
                return $this->delegate->inTransaction();
            }

            public function beginTransaction(): void
            {
                $this->delegate->beginTransaction();
            }

            public function commit(): void
            {
                $this->delegate->commit();
            }

            public function rollBack(): void
            {
                $this->delegate->rollBack();
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                return $this->delegate->fetchOne($sql, $params);
            }

            public function execute(string $sql, array $params = []): int
            {
                return $this->delegate->execute($sql, $params);
            }
        };

        $handler = new class($adapter) implements EventHandler {
            public bool $receivedSameConnection = false;
            public function __construct(private readonly ConnectionInterface $expected)
            {
            }

            public function handle(ConnectionInterface $db, array $event): void
            {
                $this->receivedSameConnection = $db === $this->expected;
                $db->execute(
                    'INSERT INTO demo_effects (source,event_id,amount) VALUES (:s,:e,33)',
                    ['s' => $event['source'], 'e' => $event['event_id']]
                );
            }
        };

        self::assertSame('accepted', (new Inbox($adapter))->receive(
            'custom', 'one', '{"id":"one","amount":33}'
        ));
        self::assertTrue((new Worker($adapter, $handler))->runOnce());
        self::assertTrue($handler->receivedSameConnection);
        self::assertSame(1, (int) $pdo->query('SELECT count(*) FROM demo_effects')->fetchColumn());
    }
}
