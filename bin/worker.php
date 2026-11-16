<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Ktulchu\WebhookInbox\Database;
use Ktulchu\WebhookInbox\DemoHandler;
use Ktulchu\WebhookInbox\Worker;

$worker = new Worker(Database::connect(), new DemoHandler());
$once = in_array('--once', $argv, true);

do {
    $worked = $worker->runOnce();
    if (!$worked && !$once) {
        sleep(1);
    }
} while (!$once);
