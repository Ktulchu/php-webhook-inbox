<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Tests;

use Ktulchu\WebhookInbox\Example\Database;
use Ktulchu\WebhookInbox\Example\DemoHandler;
use Ktulchu\WebhookInbox\EventHandler;
use Ktulchu\WebhookInbox\ConnectionInterface;
use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Receiver;
use Ktulchu\WebhookInbox\Worker;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InboxIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::connect();
        $this->db->exec('TRUNCATE TABLE demo_effects, inbox_events RESTART IDENTITY');
    }

    public function testSignedIngestionAndIdempotency(): void
    {
        $receiver = new Receiver(new Inbox(new PdoAdapter($this->db)), 'demo', 'a-signing-secret-long-enough');
        $body = '{"id":"evt-1","amount":42}';
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, 'a-signing-secret-long-enough');

        self::assertSame(202, $receiver->handle('POST', $body, $timestamp, $signature)['status']);
        self::assertSame(200, $receiver->handle('POST', $body, $timestamp, $signature)['status']);
        self::assertSame(409, $receiver->handle('POST', '{"id":"evt-1","amount":43}', $timestamp,
            'sha256=' . hash_hmac('sha256', $timestamp . '.{"id":"evt-1","amount":43}', 'a-signing-secret-long-enough'))['status']);
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM inbox_events')->fetchColumn());
    }

    public function testRejectsInvalidSignaturesAndMalformedRequests(): void
    {
        $receiver = new Receiver(new Inbox(new PdoAdapter($this->db)), 'demo', 'a-signing-secret-long-enough');
        $body = '{"id":"evt-1","amount":42}';
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, 'a-signing-secret-long-enough');

        self::assertSame(405, $receiver->handle('GET', $body, $timestamp, $signature)['status']);
        self::assertSame(401, $receiver->handle('POST', $body, (string) (time() - 301), $signature)['status']);
        self::assertSame(401, $receiver->handle('POST', $body, $timestamp, 'sha256=bad')['status']);
        self::assertSame(422, $receiver->handle('POST', '{broken', $timestamp,
            'sha256=' . hash_hmac('sha256', $timestamp . '.{broken', 'a-signing-secret-long-enough'))['status']);
        self::assertSame(413, $receiver->handle('POST', str_repeat('x', 262145), $timestamp, $signature)['status']);
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM inbox_events')->fetchColumn());
    }

    public function testWorkerCommitsBusinessEffectAndAcknowledgmentOnce(): void
    {
        $this->ingest('success', 75);
        $worker = new Worker(new PdoAdapter($this->db), new DemoHandler());

        self::assertTrue($worker->runOnce());
        self::assertFalse($worker->runOnce());
        self::assertSame('done', $this->eventStatus('success'));
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
        self::assertSame(75, (int) $this->db->query('SELECT amount FROM demo_effects')->fetchColumn());
    }

    public function testSkipLockedLeavesClaimedEventForOtherWorker(): void
    {
        $this->ingest('locked', 9);
        $first = Database::connect();
        $first->beginTransaction();
        try {
            $first->query("SELECT id FROM inbox_events WHERE event_id = 'locked' FOR UPDATE")->fetch();
            $second = new Worker(new PdoAdapter(Database::connect()), new DemoHandler());
            self::assertFalse($second->runOnce());
        } finally {
            $first->rollBack();
        }

        self::assertTrue((new Worker(new PdoAdapter(Database::connect()), new DemoHandler()))->runOnce());
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
    }

    public function testHandlerPartialWriteIsRolledBackBeforeRetry(): void
    {
        $this->ingest('retry', 20);
        $handler = new class implements EventHandler {
            public function handle(ConnectionInterface $db, array $event): void
            {
                (new DemoHandler())->handle($db, $event);
                throw new RuntimeException('Injected error after business write');
            }
        };

        self::assertTrue((new Worker(new PdoAdapter($this->db), $handler))->runOnce());
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
        self::assertSame('pending', $this->eventStatus('retry'));
        self::assertSame(1, (int) $this->db->query('SELECT attempts FROM inbox_events')->fetchColumn());

        $this->db->exec("UPDATE inbox_events SET available_at = clock_timestamp() - interval '1 second'");
        self::assertTrue((new Worker(new PdoAdapter($this->db), new DemoHandler()))->runOnce());
        self::assertSame('done', $this->eventStatus('retry'));
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
    }

    public function testExhaustedRetriesMarkEventFailed(): void
    {
        (new Inbox(new PdoAdapter($this->db)))->receive('demo', 'bad', '{"id":"bad","amount":"not_an_integer"}');
        $worker = new Worker(new PdoAdapter($this->db), new DemoHandler(), 3);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertTrue($worker->runOnce());
            $this->db->exec("UPDATE inbox_events SET available_at = clock_timestamp() - interval '1 second'");
        }

        self::assertSame('failed', $this->eventStatus('bad'));
        self::assertFalse($worker->runOnce());
        self::assertSame(3, (int) $this->db->query('SELECT attempts FROM inbox_events')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
    }

    public function testSqlErrorsFromHandlerCountTowardFailedAttempts(): void
    {
        $this->ingest('sql-error', 19);
        $badHandler = new class implements EventHandler {
            public function handle(ConnectionInterface $db, array $event): void
            {
                (new DemoHandler())->handle($db, $event);
                // Constraint/SQL statement errors abort a PostgreSQL transaction
                // unless the worker rolls back to its SAVEPOINT.
                $db->execute("INSERT INTO demo_effects (source, event_id, amount)
                    VALUES ('demo', 'invalid', 'not-an-integer')");
            }
        };
        $worker = new Worker(new PdoAdapter($this->db), $badHandler, 2);

        self::assertTrue($worker->runOnce());
        self::assertSame('pending', $this->eventStatus('sql-error'));
        self::assertSame(1, (int) $this->db->query('SELECT attempts FROM inbox_events')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
        $this->db->exec("UPDATE inbox_events SET available_at = clock_timestamp() - interval '1 second'");
        self::assertTrue($worker->runOnce());
        self::assertSame('failed', $this->eventStatus('sql-error'));
        self::assertSame(2, (int) $this->db->query('SELECT attempts FROM inbox_events')->fetchColumn());
        self::assertNotSame('', (string) $this->db->query('SELECT last_error FROM inbox_events')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
    }

    public function testProviderScopesEventIds(): void
    {
        $body = '{"id":"shared","amount":7}';
        self::assertSame('accepted', (new Inbox(new PdoAdapter($this->db)))->receive('provider-a', 'shared', $body));
        self::assertSame('accepted', (new Inbox(new PdoAdapter($this->db)))->receive('provider-b', 'shared', $body));
        self::assertSame(2, (int) $this->db->query('SELECT count(*) FROM inbox_events')->fetchColumn());
    }

    public function testRejectsFutureTimestampAndInvalidEventIds(): void
    {
        $receiver = new Receiver(new Inbox(new PdoAdapter($this->db)), 'demo', 'a-signing-secret-long-enough');
        $ts = (string) (time() + 301);
        $body = '{"id":"future","amount":5}';
        $signature = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, 'a-signing-secret-long-enough');
        self::assertSame(401, $receiver->handle('POST', $body, $ts, $signature)['status']);

        $ts = (string) time();
        foreach (['{"id":42,"amount":5}', '{"id":"a space","amount":5}', '[1,2,3]'] as $invalid) {
            $signature = 'sha256=' . hash_hmac('sha256', $ts . '.' . $invalid, 'a-signing-secret-long-enough');
            self::assertSame(422, $receiver->handle('POST', $invalid, $ts, $signature)['status']);
        }
        self::assertSame(0, (int) $this->db->query('SELECT count(*) FROM inbox_events')->fetchColumn());
    }

    private function ingest(string $id, int $amount): void
    {
        $body = json_encode(['id' => $id, 'amount' => $amount], JSON_THROW_ON_ERROR);
        self::assertSame('accepted', (new Inbox(new PdoAdapter($this->db)))->receive('demo', $id, $body));
    }

    private function eventStatus(string $id): string
    {
        $stmt = $this->db->prepare('SELECT status FROM inbox_events WHERE event_id = :id');
        $stmt->execute(['id' => $id]);
        return (string) $stmt->fetchColumn();
    }
}
