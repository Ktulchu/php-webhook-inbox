<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ktulchu\WebhookInbox\Database;
use Ktulchu\WebhookInbox\Inbox;

echo (new Inbox(Database::connect()))->receive(
    'demo',
    'concurrent',
    '{"id":"concurrent","amount":17}',
) . "\n";
