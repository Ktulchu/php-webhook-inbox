<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Database.php';

use Ktulchu\WebhookInbox\Example\Database;

// The reusable package migration includes inbox_events only.
// demo_effects belongs exclusively to the standalone example.
$db = Database::connect();
foreach ([
    dirname(__DIR__, 3) . '/database/001_create_inbox.sql',
    dirname(__DIR__) . '/database/001_demo_effects.sql',
] as $path) {
    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read migration: {$path}\n");
        exit(1);
    }
    $db->exec($sql);
}

fwrite(STDOUT, "Demo schema ready\n");
