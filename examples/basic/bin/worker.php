<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Database.php';
require dirname(__DIR__) . '/DemoHandler.php';

use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\Example\Database;
use Ktulchu\WebhookInbox\Example\DemoHandler;
use Ktulchu\WebhookInbox\Worker;

$worker = new Worker(new PdoAdapter(Database::connect()), new DemoHandler());
$once = in_array('--once', $argv, true);

do {
    $worked = $worker->runOnce();
    if (!$worked && !$once) {
        sleep(1);
    }
} while (!$once);
