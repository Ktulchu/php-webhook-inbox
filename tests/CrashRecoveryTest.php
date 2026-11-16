<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox\Tests;

use Ktulchu\WebhookInbox\Database;
use Ktulchu\WebhookInbox\DemoHandler;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Worker;
use PHPUnit\Framework\TestCase;

final class CrashRecoveryTest extends TestCase
{
    public function testKilledTransactionReleasesRowAndRollsBackBusinessWrite(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open')) {
            self::markTestSkipped('Requires a POSIX process runner');
        }

        $db = Database::connect();
        $db->exec('TRUNCATE TABLE demo_effects, inbox_events RESTART IDENTITY');
        (new Inbox($db))->receive('demo', 'crash', '{"id":"crash","amount":123}');

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/crash_with_open_transaction.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            stream_set_timeout($pipes[1], 5);
            self::assertSame("READY\n", fgets($pipes[1]), 'Crash fixture must acquire its lock');
        } finally {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        self::assertSame(0, (int) $db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
        self::assertTrue((new Worker($db, new DemoHandler()))->runOnce());
        self::assertSame(1, (int) $db->query('SELECT count(*) FROM demo_effects')->fetchColumn());
        self::assertSame('done', (string) $db->query('SELECT status FROM inbox_events')->fetchColumn());
    }
}
