# Installation and integration

This guide covers the **unreleased v0.2 branch** in [PR #2](https://github.com/Ktulchu/php-webhook-inbox/pull/2) (`feat/composer-package-v02`). It documents the real API: `Inbox`, `Receiver`, `Worker`, `EventHandler`, `ConnectionInterface`, and the optional PDO/Yii2/Doctrine adapters.

Requirements: **PHP 8.3+**, **PostgreSQL 16+**, Composer 2. The host application owns its connection and migrations. The package is **not published on Packagist**.

## 1. Install in an application

### GitHub VCS (unreleased v0.2 branch)

Run inside **your application**:

```bash
composer config repositories.webhook-inbox vcs https://github.com/Ktulchu/php-webhook-inbox.git
composer require 'ktulchu/php-webhook-inbox:dev-feat/composer-package-v02'
```

Do not use `composer require ktulchu/php-webhook-inbox` without a version/repository yet: v0.2 is neither merged into `main` nor on Packagist. Commit the **host application's** generated `composer.lock` to fix the tested revision.

### Local checkout (verified by the independent consumer in CI)

```bash
git clone --branch feat/composer-package-v02 https://github.com/Ktulchu/php-webhook-inbox.git
```

In the **consuming application's** `composer.json`, alongside its existing keys (adjust the path):

```json
{
  "repositories": [{
    "type": "path",
    "url": "../php-webhook-inbox",
    "options": {
      "versions": {"ktulchu/php-webhook-inbox": "0.2.0"}
    }
  }],
  "require": {
    "ktulchu/php-webhook-inbox": "^0.2"
  }
}
```

Then run `composer install` in a new consuming project or `composer update ktulchu/php-webhook-inbox` in an existing one. `0.2.0` is a **local-only version override**, not a released version. See [the CI consumer](../tests/fixtures/consumer/composer.json). After a real Packagist release, remove the override and use its actual published tag.

Your application needs the DB driver it uses: `ext-pdo` + `ext-pdo_pgsql` for `PdoAdapter`, an existing `yii\db\Connection` for `Yii2Adapter`, or an existing **Doctrine DBAL 4** `Connection` for `DoctrineAdapter`. Neither framework is required by the library core.

## 2. Database setup

Apply the packaged SQL via your PostgreSQL migration runner, or for example:

```bash
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 \
  -f vendor/ktulchu/php-webhook-inbox/database/001_create_inbox.sql
```

This creates **only** `inbox_events` and its index. The package does not create your application's business tables. For the end-to-end plain PHP tutorial below, also run in the **same PostgreSQL database**:

```sql
CREATE TABLE processed_webhooks (
    source VARCHAR(64) NOT NULL,
    event_id VARCHAR(200) NOT NULL,
    amount INTEGER NOT NULL CHECK (amount > 0),
    PRIMARY KEY (source, event_id)
);
```

This example table is **your application's** responsibility, not a required table of the library.

## 3. End-to-end plain PHP example

Create the following files in a **consuming PHP project** that has installed the library. Set `PG_DSN` (e.g. `pgsql:host=127.0.0.1;port=5432;dbname=webhooks`), `PG_USER`, `PG_PASSWORD`, and `WEBHOOK_SECRET` in your process environment. Set `WEBHOOK_SECRET` to the **same string** used by your demo test client.

### `bootstrap.php` — application-provided connection

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ktulchu\WebhookInbox\Adapter\PdoAdapter;

$pdo = new PDO(
    (string) getenv('PG_DSN'),
    (string) getenv('PG_USER'),
    (string) getenv('PG_PASSWORD'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

return new PdoAdapter($pdo);
```

The library does not read these environment variables itself; they belong to this **host application**. `PdoAdapter` validates the driver and error mode without changing PDO settings.

### `public/webhook.php` — HTTP receiver (demo HMAC only)

```php
<?php
declare(strict_types=1);

use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Receiver;

header('Content-Type: application/json; charset=utf-8');

if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/webhook') {
    http_response_code(404);
    echo '{"status":"not_found"}';
    exit;
}

try {
    $db = require dirname(__DIR__) . '/bootstrap.php';
    $secret = getenv('WEBHOOK_SECRET');
    if (!is_string($secret) || strlen($secret) < 16) {
        throw new RuntimeException('WEBHOOK_SECRET must be configured');
    }

    // 256 KiB + 1 byte: Receiver rejects oversized bodies.
    $rawBody = file_get_contents('php://input', false, null, 0, 262145);
    if ($rawBody === false) {
        throw new RuntimeException('Cannot read request body');
    }

    $receiver = new Receiver(new Inbox($db), 'demo', $secret);
    $result = $receiver->handle(
        $_SERVER['REQUEST_METHOD'] ?? '',
        $rawBody,
        $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? null,
        $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null
    );

    http_response_code($result['status']);
    if ($result['status'] === 405) {
        header('Allow: POST');
    }
    echo json_encode($result['body'], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log($error->getMessage());
    http_response_code(503);
    echo '{"status":"unavailable"}';
}
```

`Receiver` does **not** send HTTP headers itself; it returns an array with `status` and `body`. For authenticated demo events: **202** = new event committed, **200** = duplicate, **409** = same event ID but different raw-body hash. Invalid requests can produce 401/405/413/422. Database/transaction errors **throw**: respond with a **retriable 5xx**, never a false 202.

### `bin/worker.php` — process events

```php
<?php
declare(strict_types=1);

use Ktulchu\WebhookInbox\ConnectionInterface;
use Ktulchu\WebhookInbox\EventHandler;
use Ktulchu\WebhookInbox\Worker;

$db = require dirname(__DIR__) . '/bootstrap.php';

$handler = new class implements EventHandler {
    public function handle(ConnectionInterface $db, array $event): void
    {
        $data = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['amount'])
            || !is_int($data['amount']) || $data['amount'] <= 0) {
            throw new InvalidArgumentException('Invalid amount');
        }

        $db->execute(
            'INSERT INTO processed_webhooks (source, event_id, amount)
             VALUES (:source, :event_id, :amount)
             ON CONFLICT (source, event_id) DO NOTHING',
            [
                'source' => $event['source'],
                'event_id' => $event['event_id'],
                'amount' => $data['amount'],
            ]
        );
    }
};

$worker = new Worker($db, $handler);
$once = in_array('--once', $argv, true);
do {
    $processed = $worker->runOnce();
    if (!$processed && !$once) {
        usleep(500_000);
    }
} while (!$once);
```

The worker locks one pending event using `FOR UPDATE SKIP LOCKED`. The business insert and the inbox `done` status share the **same physical PostgreSQL connection and transaction**. Do not open another connection or use remote side effects inside the handler.

Run the PHP built-in server for **local testing only**:

```bash
# Terminal 1:
php -S 127.0.0.1:8081 -t public public/webhook.php

# Terminal 2, after an event arrives:
php bin/worker.php --once
# Or run "php bin/worker.php" under a process supervisor.
```

Send a signed request using the **same demo secret** as the receiving process (Linux/macOS):

```bash
SECRET='replace-with-a-random-secret-of-at-least-32-bytes'
BODY='{"id":"evt-001","amount":125}'
STAMP=$(date +%s)
SIG=$(printf '%s.%s' "$STAMP" "$BODY" |
  openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')

curl -i -X POST http://127.0.0.1:8081/webhook \
  -H 'Content-Type: application/json' \
  -H "X-Webhook-Timestamp: $STAMP" \
  -H "X-Webhook-Signature: sha256=$SIG" \
  --data-binary "$BODY"
```

Run the one-shot worker **after** posting; then execute these statements in the application database:

```sql
SELECT source, event_id, amount FROM processed_webhooks;
SELECT source, event_id, status, attempts FROM inbox_events;
```

Expected: one processed row (`demo`, `evt-001`, `125`) and inbox status `done`. Repeating the exact event returns HTTP **200** without creating a second business effect. Different JSON whitespace/key order changes the raw-body hash and can cause HTTP **409** even for semantically equivalent JSON.

### Windows PowerShell request to the bundled Docker demo

After cloning the **v0.2 branch**, running `Copy-Item .env.example .env` and `docker compose up --build -d`, run this on Windows PowerShell 5.1+ (no OpenSSL needed):

```powershell
$secret = 'replace-with-a-random-secret-of-at-least-32-bytes'
$body = '{"id":"evt-powershell","amount":125}'
$timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
$hmac = [Security.Cryptography.HMACSHA256]::new(
    [Text.Encoding]::UTF8.GetBytes($secret)
)
try {
    $bytes = [Text.Encoding]::UTF8.GetBytes("$timestamp.$body")
    $signature = 'sha256=' + [BitConverter]::ToString(
        $hmac.ComputeHash($bytes)
    ).Replace('-', '').ToLowerInvariant()
} finally {
    $hmac.Dispose()
}
Invoke-WebRequest -Method Post -Uri 'http://127.0.0.1:8080/webhook' `
    -ContentType 'application/json' `
    -Headers @{
        'X-Webhook-Timestamp' = $timestamp
        'X-Webhook-Signature' = $signature
    } `
    -Body ([Text.Encoding]::UTF8.GetBytes($body))

docker compose run --rm app php examples/basic/bin/worker.php --once
docker compose exec db psql -U webhooks -d webhooks `
    -c 'SELECT source, event_id, amount FROM demo_effects;'
```

**Note:** In the PowerShell code, ```` at line ends represent PowerShell's backtick continuation character after rendering. If you change `WEBHOOK_SECRET` in `.env`, change the `$secret` in this example to match.

## 4. Yii2

Install the package **in your existing Yii2 application**. Reuse its configured PostgreSQL `Yii::$app->db`. No new DSN/PDO is opened by the adapter.

**Inside a dedicated signature-authenticated webhook controller action:**

```php
use Ktulchu\WebhookInbox\Adapter\Yii2Adapter;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Receiver;
use yii\web\Response;

public function actionDemo(): array
{
    $request = \Yii::$app->request;
    $response = \Yii::$app->response;
    $response->format = Response::FORMAT_JSON;

    try {
        $receiver = new Receiver(
            new Inbox(new Yii2Adapter(\Yii::$app->db)),
            'demo',
            \Yii::$app->params['webhookSecret']
        );
        $result = $receiver->handle(
            $request->getMethod(),
            $request->getRawBody(),
            $request->headers->get('X-Webhook-Timestamp'),
            $request->headers->get('X-Webhook-Signature')
        );
        $response->statusCode = $result['status'];
        return $result['body'];
    } catch (\Throwable $error) {
        \Yii::error($error->getMessage(), 'webhook');
        $response->statusCode = 503;
        return ['status' => 'unavailable'];
    }
}
```

Configure `params['webhookSecret']` in the application, **disable CSRF only for this dedicated external webhook endpoint**, and enforce a body-size limit in the web server (Yii2's `getRawBody()` reads the whole body). The demo HMAC protocol is **not compatible with real payment providers**; replace it with your provider's verifier in production.

**Inside a Yii2 console controller action:**

```php
use Ktulchu\WebhookInbox\Adapter\Yii2Adapter;
use Ktulchu\WebhookInbox\Worker;

public function actionOnce(): int
{
    // YourHandler implements EventHandler; business SQL uses the passed adapter.
    $db = new Yii2Adapter(\Yii::$app->db);
    (new Worker($db, new YourHandler()))->runOnce();
    return 0;
}
```

For an `InboxController`, you can then run `php yii inbox/once`. The worker requires an **idle** Yii2 connection. The adapter routes Inbox SQL to **master** even when Yii2 slaves are configured and bypasses the query cache; original routing is restored after each call.

## 5. Symfony and Doctrine DBAL 4

Install the package in your Symfony application. Use its existing PostgreSQL `Doctrine\DBAL\Connection` (e.g. from DoctrineBundle), not a new connection.

**Inside a Symfony HTTP controller method** with `Connection $db` and an application-configured `$secret`:

```php
use Doctrine\DBAL\Connection;
use Ktulchu\WebhookInbox\Adapter\DoctrineAdapter;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Receiver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

public function receive(Request $request, Connection $db, string $secret): JsonResponse
{
    try {
        $receiver = new Receiver(new Inbox(new DoctrineAdapter($db)), 'demo', $secret);
        $result = $receiver->handle(
            $request->getMethod(),
            $request->getContent(),
            $request->headers->get('X-Webhook-Timestamp'),
            $request->headers->get('X-Webhook-Signature')
        );
        return new JsonResponse($result['body'], $result['status']);
    } catch (\Throwable $error) {
        return new JsonResponse(['status' => 'unavailable'], 503);
    }
}
```

Inject the signing secret and register your route through the Symfony application. Set a request size limit at the HTTP server. The built-in `Receiver` is strictly a **demo**; for real providers, verify their signatures before calling `Inbox::receive()`.

**Inside a Symfony console command/service** with injected `Connection $db` and an app-implemented `EventHandler $handler`:

```php
use Ktulchu\WebhookInbox\Adapter\DoctrineAdapter;
use Ktulchu\WebhookInbox\Worker;

$worker = new Worker(new DoctrineAdapter($db), $handler);
$worker->runOnce();
```

Both transaction management and SQL execution go through DBAL. `DoctrineAdapter::inTransaction()` checks DBAL's transaction nesting level, **not arbitrary native-driver transactions started behind its back**. Bypassing DBAL's transaction API is **unsupported** and can defeat Inbox's fail-fast transaction safety. Always use the same DBAL connection for inbox and business writes.

## 6. Custom database adapters

Implement `Ktulchu\WebhookInbox\ConnectionInterface`:

```php
interface ConnectionInterface
{
    public function inTransaction(): bool;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function fetchOne(string $sql, array $params = []): ?array;
    public function execute(string $sql, array $params = []): int;
}
```

The implementation must support named SQL parameters with keys **without** leading colons; `fetchOne()` returns an associative row or `null` and supports `INSERT ... RETURNING`; `execute()` supports PostgreSQL `SAVEPOINT` and `ROLLBACK TO SAVEPOINT`. Inbox SQL must use **master/writer with uncached reads**. All SQL for a worker turn and its event handler must use **one physical PostgreSQL connection**. `inTransaction()` must reject **caller-owned active transactions** before any admission; don't silently commit, rollback or open an alternative connection.

## 7. HTTP and production boundaries

- Validate a real provider's **native signature against the original raw HTTP bytes**; use a server-trusted source ID (max. 64 characters) and an event ID (max. 200 characters) before `Inbox::receive(source, eventId, rawBody)`.
- The **demo** protocol is `sha256=HMAC_SHA256(timestamp + "." + rawBody)`, with ±300 seconds tolerance, max. 256 KiB and an `id` matching `[A-Za-z0-9_.:-]{1,200}`. It is not a Stripe, GitHub, Robokassa or other provider verifier.
- `Inbox::receive()` throws `LogicException` if an external transaction is active; **never** return HTTP 202 unless the event is durably committed. Return a retriable **5xx** on transaction or database failures.
- A worker uses `FOR UPDATE SKIP LOCKED`. Success commits handler's database writes and the inbox `done` marker together; a handler error rolls back its partial writes to a savepoint.
- Retries are **bounded** (`maxAttempts` defaults to 3) with backoff; after exhaustion the row becomes `failed`. No automatic failed-event redrive is included.
- Remote APIs, email and queue messages are **not** atomic with this DB transaction. No remote exactly-once guarantee; use a separate outbox if your application needs external effects.
- `payload` is stored as PostgreSQL JSONB and handed to the worker as `payload::text` (may be normalized); the original request SHA-256 is used for deduplication, **not** the original body bytes in handler.
- Use HTTPS, proper secrets, production HTTP infrastructure, process supervision, logging/monitoring, backups, encryption/retention controls as appropriate. The library does not include provider-specific integrations or these operational features.

[README and standalone Docker demo](../README.md) · [Architecture ADR](adr/0003-connection-contract.md) · [Security policy](../SECURITY.md).
