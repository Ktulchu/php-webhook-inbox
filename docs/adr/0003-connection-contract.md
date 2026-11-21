# ADR 0003: Application-owned PostgreSQL connection contract

Status: Accepted for v0.2; extended for Yii3 in v0.3

## Context

v0.1 accepted PDO directly and instantiated a demo PDO connection in the package.
Yii2, Yii3 (Yii DB 2) and Doctrine DBAL maintain their own transaction state.
Directly starting or committing a raw PDO transaction underneath a framework
bypasses its transaction bookkeeping.

## Decision

Use a narrow `ConnectionInterface` with named-parameter SQL, one-row fetch and
explicit transaction primitives. The caller supplies a configured adapter for
PostgreSQL. The worker rejects already-active transactions; Yii2, Yii3 and DBAL
adapters use their respective framework transaction APIs, while a plain-PDO
adapter uses PDO's API.

Business handler calls share exactly the same adapter instance and database session
as the worker. Savepoints remain explicit PostgreSQL SQL statements (not nested
framework transactions). Demo configuration stays in examples, never in the core.

The optional Yii3 adapter accepts an application-owned Yii DB 2 PostgreSQL
`Yiisoft\\Db\\Connection\\ConnectionInterface` (via `yiisoft/db-pgsql`). It
executes raw commands on that injected connection, tracks only the transaction
it starts, rejects caller-owned Yii3 transactions and, for PDO-backed
connections, also guards against native PDO transactions. It does not create
another connection, change transaction ownership, or reuse Yii2's adapter.

## Consequences and limits

- Application credentials and connection lifecycle remain under application control.
- Core API is independent of PDO, Yii2, Yii3 and Doctrine; optional adapters opt in.
- Framework adapter is responsible for reflecting framework-owned transaction state.
- A handler **must not** start, commit or roll back its transaction or open another
  connection; doing so violates the required atomicity. Remote effects are not
  covered by the database transaction.
- SQL is intentionally PostgreSQL-specific. This is not a generic ORM/query builder.
