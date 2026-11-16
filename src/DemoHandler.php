<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox;

use JsonException;
use PDO;
use RuntimeException;

final class DemoHandler implements EventHandler
{
    public function handle(PDO $db, array $event): void
    {
        try {
            $data = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Stored payload is invalid JSON', 0, $e);
        }

        if (!is_array($data) || !isset($data['amount']) || !is_int($data['amount'])
            || $data['amount'] <= 0 || $data['amount'] > 100000000) {
            throw new RuntimeException('amount must be an integer in range 1..100000000');
        }

        $stmt = $db->prepare(
            'INSERT INTO demo_effects (source, event_id, amount)
             VALUES (:source, :event_id, :amount) ON CONFLICT (source, event_id) DO NOTHING'
        );
        $stmt->execute([
            'source' => $event['source'],
            'event_id' => $event['event_id'],
            'amount' => $data['amount'],
        ]);
    }
}
