<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Tests;

use Ktulchu\WebhookInbox\Example\Database;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConcurrentIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::connect();
        $this->db->exec('TRUNCATE TABLE demo_effects, inbox_events RESTART IDENTITY');
    }

    public function testConcurrentIdenticalDeliveriesInsertOnlyOnce(): void
    {
        $results = $this->spawn(
            array_fill(0, 12, [PHP_BINARY, __DIR__ . '/fixtures/receive_same_event.php'])
        );

        self::assertCount(12, $results);
        self::assertSame(1, count(array_filter($results, static fn(string $v): bool => $v === "accepted\n")));
        self::assertSame(11, count(array_filter($results, static fn(string $v): bool => $v === "duplicate\n")));
        self::assertSame(1, (int) $this->db->query('SELECT count(*) FROM inbox_events')->fetchColumn());
    }

    public function testFiveConcurrentWorkersProcessFiveDistinctEvents(): void
    {
        $inbox = new Inbox(new PdoAdapter($this->db));
        for ($i = 1; $i <= 5; $i++) {
            $id = 'parallel-' . $i;
            self::assertSame('accepted', $inbox->receive('demo', $id, json_encode([
                'id' => $id, 'amount' => $i,
            ], JSON_THROW_ON_ERROR)));
        }

        $this->spawn(array_fill(0, 5, [PHP_BINARY, dirname(__DIR__) . '/examples/basic/bin/worker.php', '--once']));
        self::assertSame(5, (int) $this->db->query("SELECT count(*) FROM inbox_events WHERE status = 'done'")->fetchColumn());
        self::assertSame(5, (int) $this->db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
        self::assertSame(5, (int) $this->db->query('SELECT count(DISTINCT event_id) FROM demo_effects')->fetchColumn());
    }

    /**
     * @param list<list<string>> $commands
     * @return list<string>
     */
    private function spawn(array $commands): array
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open')) {
            self::markTestSkipped('Requires POSIX child processes');
        }

        $children = [];
        foreach ($commands as $command) {
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($proc);
            $children[] = [$proc, $pipes];
        }

        $outputs = [];
        foreach ($children as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc), $err ?: 'Child process failed');
            $outputs[] = $out === false ? '' : $out;
        }
        return $outputs;
    }
}
