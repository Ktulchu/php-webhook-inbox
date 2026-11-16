<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox;

interface EventHandler
{
    /**
     * Only use $db for database-local business writes.
     * Never open a separate connection or manipulate transactions from here.
     * Remote side effects are not atomic with the inbox acknowledgment.
     *
     * @param array{id: int, source: string, event_id: string, payload: string, attempts: int} $event
     */
    public function handle(ConnectionInterface $db, array $event): void;
}
