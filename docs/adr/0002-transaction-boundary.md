# ADR 0002: Database-local atomicity over claimed exactly-once delivery

Status: Accepted for MVP

## Context
A worker can crash between performing an effect and acknowledging the event.
No transaction on one database can atomically commit an independent remote API call.

## Decision
Hold the inbox row lock while performing the demo business write using the **same**
PDO connection. Mark done before COMMIT. On handler exceptions, roll back to a
savepoint and commit only retry metadata. On process death, PostgreSQL rolls back
uncommitted data. Never call external APIs from the demo handler.

## Consequences
- Demonstrates crash-safe atomic processing for effects inside one PostgreSQL DB.
- Does not promise exactly-once execution (a handler may be invoked more than once).
- Does not guarantee exactly-once remote effects. Such use cases require
  additional domain idempotency, an outbox and/or downstream deduplication.
- For a long-running external operation, redesign with leases and fencing rather
  than holding a database transaction open.
