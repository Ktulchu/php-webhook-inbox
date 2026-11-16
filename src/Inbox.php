<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox;

use PDO;

/**
 * Ingestion is durable before the HTTP caller receives an acknowledgment.
 * Event identity is (trusted source, provider event ID).
 */
final class Inbox
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return 'accepted'|'duplicate'|'conflict' */
    public function receive(string $source, string $eventId, string $rawBody): string
    {
        $hash = hash('sha256', $rawBody);

        $insert = $this->db->prepare(
            'INSERT INTO inbox_events (source, event_id, payload, payload_sha256)
             VALUES (:source, :event_id, CAST(:payload AS jsonb), :payload_hash)
             ON CONFLICT (source, event_id) DO NOTHING
             RETURNING id'
        );
        $insert->execute([
            'source' => $source,
            'event_id' => $eventId,
            'payload' => $rawBody,
            'payload_hash' => $hash,
        ]);

        if ($insert->fetchColumn() !== false) {
            return 'accepted';
        }

        $existing = $this->db->prepare(
            'SELECT payload_sha256 FROM inbox_events
             WHERE source = :source AND event_id = :event_id'
        );
        $existing->execute(['source' => $source, 'event_id' => $eventId]);

        return $existing->fetchColumn() === $hash ? 'duplicate' : 'conflict';
    }
}
