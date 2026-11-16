<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox;

/**
 * PostgreSQL connection already configured and owned by the host application.
 *
 * Named parameters use keys without the leading colon. All operations (including
 * handler effects) must run on the same physical connection during a worker turn.
 */
interface ConnectionInterface
{
    public function inTransaction(): bool;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /** @param array<string, mixed> $params */
    public function execute(string $sql, array $params = []): int;
}
