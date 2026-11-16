<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox;

use PDO;

interface EventHandler
{
    /**
     * Must only perform DB operations through $db in the worker transaction.
     * External side effects cannot share the transaction's atomicity.
     * Do not begin/commit/rollback transactions or execute transaction-control SQL.
     *
     * @param array{id: int, source: string, event_id: string, payload: string, attempts: int} $event
     */
    public function handle(PDO $db, array $event): void;
}
