<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox;

/** Durable, provider-scoped event admission on the host's PostgreSQL connection. */
final class Inbox
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /** @return 'accepted'|'duplicate'|'conflict' */
    public function receive(string $source, string $eventId, string $rawBody): string
    {
        $hash = hash('sha256', $rawBody);

        $inserted = $this->db->fetchOne(
            'INSERT INTO inbox_events (source, event_id, payload, payload_sha256)
             VALUES (:source, :event_id, CAST(:payload AS jsonb), :payload_hash)
             ON CONFLICT (source, event_id) DO NOTHING
             RETURNING id',
            [
                'source' => $source,
                'event_id' => $eventId,
                'payload' => $rawBody,
                'payload_hash' => $hash,
            ]
        );
        if ($inserted !== null) {
            return 'accepted';
        }

        $row = $this->db->fetchOne(
            'SELECT payload_sha256 FROM inbox_events
             WHERE source = :source AND event_id = :event_id',
            ['source' => $source, 'event_id' => $eventId]
        );

        return $row !== null && $row['payload_sha256'] === $hash ? 'duplicate' : 'conflict';
    }
}
