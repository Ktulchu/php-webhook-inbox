<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Database.php';

use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\Example\Database;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Receiver;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/webhook') {
    http_response_code(404);
    echo '{"status":"not_found"}';
    exit;
}

try {
    $secret = getenv('WEBHOOK_SECRET');
    if (!is_string($secret) || strlen($secret) < 16) {
        throw new RuntimeException('WEBHOOK_SECRET must contain at least 16 characters');
    }

    $source = getenv('WEBHOOK_SOURCE') ?: 'demo';
    $receiver = new Receiver(new Inbox(new PdoAdapter(Database::connect())), $source, $secret);
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    if ($raw === false) {
        throw new RuntimeException('Failed to read request body');
    }

    $result = $receiver->handle(
        $_SERVER['REQUEST_METHOD'] ?? '',
        $raw,
        $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? null,
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null,
    );
    http_response_code($result['status']);
    if ($result['status'] === 405) {
        header('Allow: POST');
    }
    echo json_encode($result['body'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo '{"status":"unavailable"}';
}
