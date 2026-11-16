<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Ktulchu\WebhookInbox\Database;

$sql = file_get_contents(dirname(__DIR__) . '/database/001_create_inbox.sql');
if ($sql === false) {
    fwrite(STDERR, "Cannot read migration\n");
    exit(1);
}

Database::connect()->exec($sql);
fwrite(STDOUT, "Schema ready\n");
