<?php
declare(strict_types=1);

namespace Ktulchu\WebhookInbox;

use PDO;
use Throwable;

final class Worker
{
    public function __construct(
        private readonly PDO $db,
        private readonly EventHandler $handler,
        private readonly int $maxAttempts = 3,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new \InvalidArgumentException('maxAttempts must be between 1 and 20');
        }
    }

    /**
     * Handles at most one event. A crash before COMMIT rolls back both the
     * business effect and the processing acknowledgment.
     */
    public function runOnce(): bool
    {
        $this->db->beginTransaction();

        try {
            $claim = $this->db->query(
                "SELECT id, source, event_id, payload::text AS payload, attempts
                 FROM inbox_events
                 WHERE status = 'pending' AND available_at <= clock_timestamp()
                 ORDER BY available_at, id
                 LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $row = $claim->fetch();

            if ($row === false) {
                $this->db->commit();
                return false;
            }

            /** @var array{id: int, source: string, event_id: string, payload: string, attempts: int} $event */
            $event = [
                'id' => (int) $row['id'],
                'source' => $row['source'],
                'event_id' => $row['event_id'],
                'payload' => $row['payload'],
                'attempts' => (int) $row['attempts'],
            ];

            // A handler failure must undo any partial business writes, while
            // retaining retry bookkeeping in the enclosing transaction.
            $this->db->exec('SAVEPOINT before_handler');
            try {
                $this->handler->handle($this->db, $event);
                $update = $this->db->prepare(
                    "UPDATE inbox_events
                     SET status = 'done', attempts = attempts + 1,
                         processed_at = clock_timestamp(), last_error = NULL
                     WHERE id = :id"
                );
                $update->execute(['id' => $event['id']]);
            } catch (Throwable $error) {
                $this->db->exec('ROLLBACK TO SAVEPOINT before_handler');

                // SQL errors from business statements can be permanent: count
                // them too. If the DB is unreachable, retry bookkeeping will
                // fail and the outer transaction is rolled back instead.

                $attempt = $event['attempts'] + 1;
                $failed = $attempt >= $this->maxAttempts;
                $delay = min(3600, 2 ** min($attempt, 11));

                $update = $this->db->prepare(
                    "UPDATE inbox_events
                     SET status = :status, attempts = :attempts,
                         available_at = clock_timestamp() + make_interval(secs => CAST(:delay AS integer)),
                         last_error = :last_error
                     WHERE id = :id"
                );
                $update->execute([
                    'status' => $failed ? 'failed' : 'pending',
                    'attempts' => $attempt,
                    'delay' => $failed ? 0 : $delay,
                    'last_error' => substr($error->getMessage(), 0, 1000),
                    'id' => $event['id'],
                ]);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
