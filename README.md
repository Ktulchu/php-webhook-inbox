# PHP Webhook Inbox

[![CI](https://github.com/Ktulchu/php-webhook-inbox/actions/workflows/ci.yml/badge.svg)](https://github.com/Ktulchu/php-webhook-inbox/actions/workflows/ci.yml)

**A small, framework-independent PostgreSQL webhook inbox for PHP 8.3+.**

Durable admission, provider-scoped idempotency, concurrent workers via `FOR UPDATE SKIP LOCKED`, transaction-local business effects, bounded retries and crash recovery. No Redis, RabbitMQ or ORM.

> **Status:** v0.2 candidate. The library is not published on Packagist yet. Use a Composer path repository in another application for local integration, or a VCS repository for a specific Git ref. The demo signature protocol is not compatible with real payment providers without adaptation.

## Use from another PHP application

The package accepts an **application-owned connection** via `ConnectionInterface`; it never reads credentials or opens connections itself. The public `Inbox`, `Worker` and `EventHandler` APIs contain **no PDO, Yii2 or Doctrine types**.

### Install (before Packagist release)

In your application `composer.json` configure a path repository for a local checkout of this project:

```json
{
  "repositories": [
    {"type": "path", "url": "../php-webhook-inbox"}
  ],
  "require": {
    "ktulchu/php-webhook-inbox": "@dev"
  }
}
```

Then run `composer update ktulchu/php-webhook-inbox`. An isolated consumer project is exercised in CI under `tests/fixtures/consumer`. After publishing on Packagist, the `repositories` override can be removed and a tagged version used instead.

### Connect (plain PHP, existing PDO)

```php
use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\Inbox;

$connection = new PdoAdapter($pdo); // your existing PostgreSQL PDO
$result = (new Inbox($connection))->receive(
    'billing-provider', 'event-123', '{"id":"event-123","amount":42}'
);
// 'accepted' | 'duplicate' | 'conflict'
```

The SQL schema is [database/001_create_inbox.sql](database/001_create_inbox.sql).
For real webhook endpoints, verify the provider-native signature **before** calling `Inbox::receive()`. The [local demo](examples/basic/) includes a *separate, illustrative* HMAC receiver.

### Handle the event

```php
use Ktulchu\WebhookInbox\ConnectionInterface;
use Ktulchu\WebhookInbox\EventHandler;
use Ktulchu\WebhookInbox\Worker;

$handler = new class implements EventHandler {
    public function handle(ConnectionInterface $db, array $event): void
    {
        $data = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
        // Example only: validate event business fields before any real update.
        $db->execute(
            'UPDATE orders SET paid = TRUE WHERE provider_event_id = :id',
            ['id' => $event['event_id']]
        );
    }
};

$worker = new Worker($connection, $handler);
$worker->runOnce(); // true if an event was claimed, false when no work was ready
```

The handler receives the **same adapter instance** as the worker. It must use that adapter for business writes, and must not start/commit/roll back transactions itself. No outgoing HTTP requests, emails or broker publishing inside this transactional handler.

### Choose your adapter

| App | Adapter | Optional dependency |
|---|---|---|
| Plain PHP | `Adapter\PdoAdapter($pdo)` | `ext-pdo`, `ext-pdo_pgsql` |
| Yii2 | `Adapter\Yii2Adapter(Yii::$app->db)` | `yiisoft/yii2` |
| Symfony/Doctrine DBAL 4 | `Adapter\DoctrineAdapter($connection)` | `doctrine/dbal:^4` |
| Custom | Implement `ConnectionInterface` | Your driver |

Only PostgreSQL is supported. Detailed integration snippets are in [docs/integration.md](docs/integration.md).

## Run the standalone demo

Requires Docker Compose. The example uses local-only credentials and the PHP built-in web server.

```bash
git clone https://github.com/Ktulchu/php-webhook-inbox.git
cd php-webhook-inbox
cp .env.example .env
docker compose up --build -d
```

Send a signed demo request (Linux/macOS shell):

```bash
SECRET='replace-with-a-random-secret-of-at-least-32-bytes'
BODY='{"id":"evt-001","amount":125}'
STAMP=$(date +%s)
SIG=$(printf '%s.%s' "$STAMP" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')
curl -i -X POST http://127.0.0.1:8080/webhook \
  -H 'Content-Type: application/json' \
  -H "X-Webhook-Timestamp: $STAMP" \
  -H "X-Webhook-Signature: sha256=$SIG" \
  --data-binary "$BODY"
docker compose run --rm app php examples/basic/bin/worker.php --once
docker compose exec db psql -U webhooks -d webhooks \
  -c 'SELECT source, event_id, amount FROM demo_effects;'
```

Expected response: 202 accepted, same bytes and valid signature => 200 duplicate, same event ID but different bytes => 409 conflict. `docker compose --profile worker up -d --scale worker=3 worker` runs long-lived workers. **`docker compose down -v` deletes the demo DB volume.**

## Guarantees

| Condition | Behavior |
|---|---|
| Same signed webhook delivered repeatedly | One inbox event per `(source, event_id)` |
| Multiple workers claim work | Row-level locks and `SKIP LOCKED` prevent concurrent processing of the same event |
| Handler throws, including an SQL exception | Roll back to savepoint, commit attempt/backoff state |
| Process dies before commit | PostgreSQL rolls back business writes and acknowledgment together |
| Handler and acknowledgment in the same database transaction | Both commit or both roll back |
| Worker started in an existing app transaction | Fails fast without touching the caller's transaction |
| External HTTP/email/queue effects | **No exactly-once guarantee**; deliberately outside scope |

The system provides **at-least-once execution** with database-local atomic writes. Providers can retry after losing the HTTP response. No arbitrary remote exactly-once guarantee exists. Locks are held while a handler runs: keep handlers short and database-local.

## Design choices, testing and limitations

The `ConnectionInterface` only exposes `beginTransaction`, `commit`, `rollBack`, `inTransaction`, `fetchOne`, `execute`. Named query parameters are supported (without colons in the associative keys). Savepoints use PostgreSQL SQL statements and are not a general nested-transaction API.

- **Transactions must be started via the corresponding framework adapter.** Do not directly call PDO transaction APIs underneath Yii2 or Doctrine. Both frameworks maintain their own transaction bookkeeping; mixing raw and managed transaction calls can corrupt it.
- The worker always requires an **idle connection** before its transaction. The adapter, handler and inbox must refer to the *same physical PostgreSQL session*. Forcing a new connection inside a handler breaks the guarantee.
- The HMAC receiver is a **demo protocol**: `HMAC-SHA256(timestamp . "." . rawBody)`, ±300 seconds, maximum 256 KiB, trusted source configured server-side; **not** a Stripe/GitHub/Robokassa adapter. Use HTTPS, proper provider-native verification and key rotation for real deployments.
- This sample does not implement retention, failed-event redrive, operational metrics, backups, bounded runtime per handler, or production HTTP infrastructure. Raw event JSON is stored unencrypted.
- Sample credentials in `.env.example` are only for localhost. `PDO` is required for the standalone demo, but **not for users of the core interface** with another supported driver.

**CI** checks PHPUnit with PostgreSQL, PHPStan, dependency security audit, Docker HTTP smoke, framework adapters, and installation from an independent Composer consumer.

[ADR 0001: PostgreSQL instead of a broker](docs/adr/0001-postgres-inbox.md) · [ADR 0002: Transaction boundary](docs/adr/0002-transaction-boundary.md) · [Security policy](SECURITY.md)

MIT License.
