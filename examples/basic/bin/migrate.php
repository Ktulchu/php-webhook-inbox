<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Database.php';

use Ktulchu\WebhookInbox\Example\Database;

$sql = file_get_contents(dirname(__DIR__, 3) . '/database/001_create_inbox.sql');
if ($sql === false) {
    fwrite(STDERR, "Cannot read migration\n");
    exit(1);
}

Database::connect()->exec($sql);
fwrite(STDOUT, "Schema ready\n");
