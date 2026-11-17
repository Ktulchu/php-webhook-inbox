# PHP Webhook Inbox

[![CI](https://github.com/Ktulchu/php-webhook-inbox/actions/workflows/ci.yml/badge.svg)](https://github.com/Ktulchu/php-webhook-inbox/actions/workflows/ci.yml)

**Transactional PostgreSQL webhook inbox for PHP 8.3+, without a framework dependency.** Admit each authenticated event once per provider, then let concurrent workers apply database-local effects atomically using `FOR UPDATE SKIP LOCKED`, savepoints and bounded retries.

## Project background

The webhook processing problems and design decisions behind this library come from years of commercial integration work. This public repository contains a standalone, generalized implementation rather than the original application's code. The earlier development history stays in private commercial GitLab; the public GitHub history begins with extraction and packaging, and this release has its own tests and lifecycle.

**Version:** `v0.2.0` is the first tagged GitHub release. To install from Packagist, the maintainer must first register the repository at [packagist.org](https://packagist.org/); until the [package listing](https://packagist.org/packages/ktulchu/php-webhook-inbox) is available, use the GitHub VCS repository instructions below. This is an early release, not a claim of security-audited production readiness.

## Requirements

- PHP **8.3+**, Composer 2, **PostgreSQL 16+**.
- With `PdoAdapter`: PHP extensions `pdo` and `pdo_pgsql`, an existing PostgreSQL PDO configured with `PDO::ERRMODE_EXCEPTION`.
- With `Yii2Adapter`: an existing `yii\db\Connection` from your Yii2 application.
- With `DoctrineAdapter`: an existing **Doctrine DBAL 4** `Connection` (e.g. in Symfony).
- The library **does not create DB connections or run migrations** for your application. Yii2 and Doctrine are optional, not required by the core.

## Install v0.2 in an existing application

### Option A: Packagist (after the package is registered)

Once the [Packagist package listing](https://packagist.org/packages/ktulchu/php-webhook-inbox) exists and includes tag `v0.2.0`, run inside **your application**:

```bash
composer require 'ktulchu/php-webhook-inbox:^0.2'
```

**If the package is not listed yet**, use the GitHub VCS repository instead:

```bash
composer config repositories.webhook-inbox vcs https://github.com/Ktulchu/php-webhook-inbox.git
composer require 'ktulchu/php-webhook-inbox:^0.2'
```

Both commands select the **tagged v0.2.0 release**, not the old development branch. Your application must already have its chosen database driver and framework. Commit the application's `composer.lock`.

### Option B: Local Composer `path` repository (tested by CI)

Clone the tagged release **next to your application**:

```bash
git clone --branch v0.2.0 https://github.com/Ktulchu/php-webhook-inbox.git
```

In the **consuming application's** `composer.json` (adjust the relative path to suit your directories), add:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../php-webhook-inbox",
      "options": {
        "versions": {"ktulchu/php-webhook-inbox": "0.2.0"}
      }
    }
  ],
  "require": {
    "ktulchu/php-webhook-inbox": "^0.2"
  }
}
```

These are the relevant fields of `composer.json`: **merge** them with your existing `require` and `repositories`, do not replace the entire application configuration. Run `composer install` for a new consumer, or `composer update ktulchu/php-webhook-inbox` in an existing project.

**Important:** `0.2.0` here is a **local path-repository version override** (as in [our tested consumer](tests/fixtures/consumer/composer.json)), not a Packagist publishing step. Remove the path repository and override when switching to the Packagist release.

## Create the database table

Run the library schema once through your application's migration tool, or directly with `psql` (use your own connection settings):

```bash
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 \
  -f vendor/ktulchu/php-webhook-inbox/database/001_create_inbox.sql
```

This creates **only** `inbox_events` and its index. The table `demo_effects` belongs to the standalone example; it is **not** part of the library's migration. Source names are trusted server-side provider identifiers (max. 64 characters); event IDs must fit 200 characters.

## Use it (plain PHP)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Worker;

// These credentials / this PDO are owned by your application.
$pdo = new PDO(getenv('PG_DSN'), getenv('PG_USER'), getenv('PG_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db = new PdoAdapter($pdo);

// IMPORTANT: authenticate the real provider and extract its event ID first.
// The source is server-side configuration, never an unchecked client header.
$inbox = new Inbox($db);
$result = $inbox->receive('my-provider', 'event-123', '{"id":"event-123","amount":42}');
// 'accepted' (new, committed) | 'duplicate' (same raw bytes) | 'conflict' (same ID, different bytes)
```

**Never call `receive()` inside an open application transaction**: it throws `LogicException` before writing, rather than risking HTTP 202 for a future rollback. `Inbox::receive()` assumes the underlying driver is in auto-commit mode when no transaction is active.

Create an `EventHandler` for your application's **database-local** business writes and pass the **same connection adapter** into the worker:

```php
use Ktulchu\WebhookInbox\ConnectionInterface;
use Ktulchu\WebhookInbox\EventHandler;
use Ktulchu\WebhookInbox\Worker;

$handler = new class implements EventHandler {
    public function handle(ConnectionInterface $db, array $event): void
    {
        $payload = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
        // Verify your business fields; example table belongs to your application.
        $db->execute(
            'INSERT INTO processed_webhooks (source, event_id) VALUES (:source, :id)
             ON CONFLICT (source, event_id) DO NOTHING',
            ['source' => $event['source'], 'id' => $event['event_id']]
        );
    }
};

$worker = new Worker($db, $handler);
$worker->runOnce(); // true: one event claimed; false: no ready event
```

Here `processed_webhooks` is an **example business table to create yourself**. See the [complete step-by-step example](docs/integration.md) for its SQL, a signed HTTP endpoint, a worker CLI, and Yii2/Symfony integration.

## Run the bundled Docker demo

This is a **separate local demonstration**, not an instruction to deploy PHP's built-in server to production. Requires Docker Compose and OpenSSL for the sample signed request.

```bash
git clone --branch v0.2.0 https://github.com/Ktulchu/php-webhook-inbox.git
cd php-webhook-inbox
cp .env.example .env
docker compose up --build -d
```

On Windows PowerShell, replace `cp` with `Copy-Item .env.example .env`.

With the **example** `.env` (local testing only), send a signed request:

```bash
SECRET='replace-with-a-random-secret-of-at-least-32-bytes'
BODY='{"id":"evt-001","amount":125}'
STAMP=$(date +%s)
SIG=$(printf '%s.%s' "$STAMP" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')
curl -i -X POST http://127.0.0.1:8080/webhook \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Timestamp: $STAMP" \
  -H "X-Webhook-Signature: sha256=$SIG" \
  --data-binary "$BODY"
docker compose run --rm app php examples/basic/bin/worker.php --once
docker compose exec db psql -U webhooks -d webhooks \
  -c 'SELECT source, event_id, amount FROM demo_effects;'
```

Expected: **202** on first delivery, **200** when the same signed event is resent, **409** for a different body with the same source and event ID. The one-shot worker inserts **one** row with amount `125` into `demo_effects`. Run continuous workers with:

```bash
docker compose --profile worker up -d --scale worker=3 worker
```

**Never run `docker compose down -v` if you want to keep the demo data**; `-v` deletes its PostgreSQL volume. Replace example secrets before exposing any service. This example listens on `127.0.0.1:8080`.

## Response codes, guarantees and caveats

`Receiver` implements **only the example** `HMAC-SHA256(timestamp + "." + exact raw body)` protocol, with ±300 seconds tolerance, 256 KiB maximum body and an `id` field. It is **not** a signature verifier for Stripe, Robokassa, GitHub or any other real provider. A real integration must verify the provider's **own** signature before calling `Inbox::receive()`. For the demo receiver: HTTP **202** = accepted, **200** = duplicate, **409** = ID/hash conflict; 401/405/413/422 cover invalid requests. Database errors and active-transaction failures throw; **your HTTP adapter must return a retriable 5xx** (the bundled example returns **503**), not 202.

- `(source, event_id)` is unique; equal **raw byte hashes** are duplicates, differing hashes are conflicts. JSON key order/whitespace differences are significant.
- PostgreSQL `FOR UPDATE SKIP LOCKED` prevents concurrent workers from claiming the same row. Worker and handler writes commit in the **same PostgreSQL transaction**.
- On handler errors, the worker rolls back to a savepoint, records an attempt, schedules bounded exponential retry and eventually marks the event `failed`. A process crash before commit rolls back the handler writes and acknowledgment together.
- The handler must use the worker's **same adapter/physical connection** and must **not** manage transactions or perform remote calls, send emails or publish messages. External side effects **do not** get exactly-once guarantees.
- Yii2 adapter explicitly routes critical queries to **master** and disables its query cache, even if the application has slaves enabled. Doctrine uses DBAL transaction bookkeeping; bypassing it with native-driver transactions is **unsupported**.
- PostgreSQL tables and business effects must be in the **same database** for atomicity. The library does **not** supply provider integrations, operational monitoring, failed-event redrive, retention, backups or production HTTP infrastructure. Payloads are stored as plaintext JSONB.

[Detailed PHP / Yii2 / Symfony examples](docs/integration.md) · [Architectural decisions](docs/adr/0003-connection-contract.md) · [Security](SECURITY.md) · MIT License.

**CI** covers PostgreSQL integration tests, optional-framework contract tests, a separate Composer consumer, PHPStan, Composer audit and Docker smoke testing. See [v0.2.0 release notes](docs/releases/v0.2.0.md).
