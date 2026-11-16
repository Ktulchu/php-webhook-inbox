<?php
declare(strict_types=1);

// Run only after: composer install inside this independent consumer directory.
require __DIR__ . '/vendor/autoload.php';

use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\ConnectionInterface;
use Ktulchu\WebhookInbox\EventHandler;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Worker;

$pdo = new PDO(
    sprintf('pgsql:host=%s;dbname=%s',
        getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_NAME') ?: 'webhooks'),
    getenv('DB_USER') ?: 'webhooks',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec('TRUNCATE TABLE demo_effects, inbox_events RESTART IDENTITY');
$adapter = new PdoAdapter($pdo);

$handler = new class implements EventHandler {
    public function handle(ConnectionInterface $db, array $event): void
    {
        $db->execute('INSERT INTO demo_effects (source,event_id,amount) VALUES (:s,:e,11)',
            ['s' => $event['source'], 'e' => $event['event_id']]
        );
    }
};

if ((new Inbox($adapter))->receive('consumer', 'one', '{"id":"one","amount":11}') !== 'accepted'
    || (new Inbox($adapter))->receive('consumer', 'one', '{"id":"one","amount":11}') !== 'duplicate'
    || !(new Worker($adapter, $handler))->runOnce()
    || (int) $adapter->fetchOne('SELECT count(*) AS n FROM demo_effects')['n'] !== 1
    || $adapter->fetchOne("SELECT status FROM inbox_events WHERE event_id='one'")['status'] !== 'done') {
    throw new RuntimeException('Consumer package integration failed');
}
echo "Independent Composer consumer verified\n";
