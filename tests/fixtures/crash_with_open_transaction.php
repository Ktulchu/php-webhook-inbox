<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ktulchu\WebhookInbox\Example\Database;

$db = Database::connect();
$db->beginTransaction();
$db->query("SELECT id FROM inbox_events WHERE event_id = 'crash' FOR UPDATE")->fetch();
$db->exec("INSERT INTO demo_effects(source, event_id, amount) VALUES ('demo','crash',123)");
echo "READY\n";
flush();
// The parent test kills this process. Both writes and locks must roll back.
sleep(30);
$db->commit();
