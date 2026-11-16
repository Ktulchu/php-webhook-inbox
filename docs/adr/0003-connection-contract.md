# ADR 0003: Application-owned PostgreSQL connection contract

Status: Accepted for v0.2

## Context

v0.1 accepted PDO directly and instantiated a demo PDO connection in the package.
Yii2 and Doctrine DBAL both maintain independent transaction nesting state. Directly
starting or committing a raw PDO transaction under these frameworks bypasses it.

## Decision

Use a narrow `ConnectionInterface` with named-parameter SQL, one-row fetch and
explicit transaction primitives. The caller supplies a configured adapter for
PostgreSQL. The worker rejects already-active transactions; Yii2 and DBAL adapters
use the framework's transaction API, while a plain-PDO adapter uses PDO's API.

Business handler calls share exactly the same adapter instance and database session
as the worker. Savepoints remain explicit PostgreSQL SQL statements (not nested
framework transactions). Demo configuration stays in examples, never in the core.

## Consequences and limits

- Application credentials and connection lifecycle remain under application control.
- Core API is independent of PDO, Yii2 and Doctrine; optional adapters opt in.
- Framework adapter is responsible for reflecting framework-owned transaction state.
- A handler **must not** start, commit or roll back its transaction or open another
  connection; doing so violates the required atomicity. Remote effects are not
  covered by the database transaction.
- SQL is intentionally PostgreSQL-specific. This is not a generic ORM/query builder.
