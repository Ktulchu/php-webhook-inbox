<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ktulchu\WebhookInbox\Example\Database;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Adapter\PdoAdapter;

echo (new Inbox(new PdoAdapter(Database::connect())))->receive(
    'demo',
    'concurrent',
    '{"id":"concurrent","amount":17}',
) . "\n";
