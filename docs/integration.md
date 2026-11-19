# Installation and integration

This guide documents the **v0.3.0 GitHub release** and its real API: `Inbox`, `Receiver`, `Worker`, `EventHandler`, `ConnectionInterface`, and optional PDO/Yii2/Yii3/Doctrine adapters.

Requirements: **PHP 8.3+**, **PostgreSQL 16+**, Composer 2. The host application owns its connection and migrations. **Packagist availability requires registration by the maintainer**; use the GitHub VCS fallback until the [package listing](https://packagist.org/packages/ktulchu/php-webhook-inbox) is active.

## 1. Install in an application

### Packagist (once registered)

Once [ktulchu/php-webhook-inbox](https://packagist.org/packages/ktulchu/php-webhook-inbox) is listed with tag `v0.3.0`, run inside your application:

```bash
composer require 'ktulchu/php-webhook-inbox:^0.3'
```

Until the maintainer registers the package, use the **tagged GitHub release directly**:

```bash
composer config repositories.webhook-inbox vcs https://github.com/Ktulchu/php-webhook-inbox.git
composer require 'ktulchu/php-webhook-inbox:^0.3'
```

Commit the host application's `composer.lock` to pin the actual revision. These instructions select the `v0.3.0` tag, not the previous PR development branch.

### Local checkout (verified by the independent consumer in CI)

```bash
git clone --branch v0.3.0 https://github.com/Ktulchu/php-webhook-inbox.git
```

In the **consuming application's** `composer.json`, alongside its existing keys (adjust the path):

```json
{
  "repositories": [{
    "type": "path",
    "url": "../php-webhook-inbox",
    "options": {
      "versions": {"ktulchu/php-webhook-inbox": "0.3.0"}
    }
  }],
  "require": {
    "ktulchu/php-webhook-inbox": "^0.3"
  }
}
```

Then run `composer install` in a new consuming project or `composer update ktulchu/php-webhook-inbox` in an existing one. `0.3.0` here is a **local-only version override**, separate from the released tag. See [the CI consumer](../tests/fixtures/consumer/composer.json). After the package appears on Packagist, remove the path override and install the public `^0.3` release.

Your application needs the DB driver it uses: `ext-pdo` + `ext-pdo_pgsql` for `PdoAdapter`, an existing `yii\db\Connection` for `Yii2Adapter`, `yiisoft/db-pgsql:^2.0` for `Yii3Adapter`, or an existing **Doctrine DBAL 4** `Connection` for `DoctrineAdapter`. Framework packages are optional host dependencies, not required by the library core.

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

After cloning the **v0.3.0 tag**, running `Copy-Item .env.example .env` and `docker compose up --build -d`, run this on Windows PowerShell 5.1+ (no OpenSSL needed):

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

**PowerShell note:** A single backtick at the end of a command line is PowerShell's continuation character; do not leave spaces after it. If you change `WEBHOOK_SECRET` in `.env`, change `$secret` above to match.

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

## 5. Yii3 (Yii DB 2, PostgreSQL)

**Available since v0.3.0.** It uses the Yii3 database API, **not** the Yii2 `yii\\db\\Connection` class.

Install `yiisoft/db-pgsql:^2.0` in your existing Yii3 application and inject its configured PostgreSQL database connection. The library does not open a second connection, register a container binding, or change transaction settings.

```bash
composer require 'ktulchu/php-webhook-inbox:^0.3' 'yiisoft/db-pgsql:^2.0'
```

If the release is not yet available on Packagist, first add the GitHub VCS repository described in section 1. No development-branch constraint is needed.

```php
use Ktulchu\WebhookInbox\Adapter\Yii3Adapter;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Worker;
use Yiisoft\Db\Connection\ConnectionInterface as YiiConnection;

// $connection is the application's PostgreSQL Yii DB connection
// (provided by the Yii3 DI container).
$db = new Yii3Adapter($connection);
$status = (new Inbox($db))->receive('provider', 'event-123', $rawBody);

// YourHandler implements EventHandler and writes through the $db argument.
$processed = (new Worker($db, new YourHandler()))->runOnce();
```

The receiving endpoint must first authenticate the actual provider signature using the original HTTP request bytes. Do not use the demo HMAC protocol as a substitute for provider verification. In production, use separate request and worker lifecycles, and return a retriable **5xx** on database or transaction failure. Never return **202** for an event not committed to the inbox.

Both `Inbox` and the worker require an idle connection at their entry points. The adapter tracks only transactions it starts; caller-managed Yii3 transactions are rejected without modification. With a PDO-backed Yii3 connection it also detects native PDO transactions started outside Yii3. Raw SQL commands use the injected connection and do not use Yii2's replica routing/query cache.

## 6. Symfony and Doctrine DBAL 4

Install the inbox package in your existing Symfony application. Configure its **Doctrine DBAL 4 PostgreSQL** `Doctrine\DBAL\Connection` service; the library does not create a connection or install Symfony itself.

**`src/Controller/DemoWebhookController.php`** — a complete illustrative controller for the *demo* HMAC (Symfony 6.4+/7+, using attribute routes):

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Ktulchu\WebhookInbox\Adapter\DoctrineAdapter;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Receiver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DemoWebhookController
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/webhook/demo', name: 'webhook_demo', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $receiver = new Receiver(
                new Inbox(new DoctrineAdapter($this->db)),
                'demo',
                $this->webhookSecret
            );
            $result = $receiver->handle(
                $request->getMethod(),
                $request->getContent(),
                $request->headers->get('X-Webhook-Timestamp'),
                $request->headers->get('X-Webhook-Signature')
            );
            return new JsonResponse($result['body'], $result['status']);
        } catch (\Throwable $error) {
            // Log through the host app's logger as appropriate.
            return new JsonResponse(['status' => 'unavailable'], 503);
        }
    }
}
```

The host application must have attribute routing and controller services enabled (as in a typical Symfony project). Supply the scalar secret via its existing configuration; for example, merge this service definition into `config/services.yaml` (retain your existing service defaults, autowiring and bindings):

```yaml
services:
    App\Controller\DemoWebhookController:
        arguments:
            $webhookSecret: '%env(WEBHOOK_SECRET)%'
```

The `WEBHOOK_SECRET` variable is a **demo signing secret**, not the signing format of any real provider. Configure HTTPS and a request-size limit at your web server; `Request::getContent()` reads the HTTP body before the demo Receiver checks its 256 KiB size limit. A real integration must first verify **its provider's native signature** and then call `Inbox::receive()` directly.

**`src/Command/InboxOnceCommand.php`** — a one-shot worker command using your application's own handler:

```php
<?php
declare(strict_types=1);

namespace App\Command;

use App\Webhook\PaymentHandler; // Implement EventHandler in your application.
use Doctrine\DBAL\Connection;
use Ktulchu\WebhookInbox\Adapter\DoctrineAdapter;
use Ktulchu\WebhookInbox\Worker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:webhook-inbox:once')]
final class InboxOnceCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentHandler $handler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new Worker(new DoctrineAdapter($this->db), $this->handler))->runOnce();
        return Command::SUCCESS;
    }
}
```

Implement `App\Webhook\PaymentHandler` with `EventHandler::handle(ConnectionInterface $db, array $event)` as in the plain PHP example, and register it as a Symfony service. Run `php bin/console app:webhook-inbox:once` after receiving an event, or schedule repeated runs under your supervisor.

Both SQL execution and transaction management stay inside DBAL. `DoctrineAdapter::inTransaction()` detects DBAL-managed transactions, **not necessarily transactions started directly via its underlying native driver**. Bypassing the DBAL transaction API is **unsupported**; it can defeat the admission guard and break transaction bookkeeping. Inbox and the handler must use the same physical PostgreSQL connection.

## 7. Custom database adapters

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

## 8. HTTP and production boundaries

- Validate a real provider's **native signature against the original raw HTTP bytes**; use a server-trusted source ID (max. 64 characters) and an event ID (max. 200 characters) before `Inbox::receive(source, eventId, rawBody)`.
- The **demo** protocol is `sha256=HMAC_SHA256(timestamp + "." + rawBody)`, with ±300 seconds tolerance, max. 256 KiB and an `id` matching `[A-Za-z0-9_.:-]{1,200}`. It is not a Stripe, GitHub, Robokassa or other provider verifier.
- `Inbox::receive()` throws `LogicException` if an external transaction is active; **never** return HTTP 202 unless the event is durably committed. Return a retriable **5xx** on transaction or database failures.
- A worker uses `FOR UPDATE SKIP LOCKED`. Success commits handler's database writes and the inbox `done` marker together; a handler error rolls back its partial writes to a savepoint.
- Retries are **bounded** (`maxAttempts` defaults to 3) with backoff; after exhaustion the row becomes `failed`. No automatic failed-event redrive is included.
- Remote APIs, email and queue messages are **not** atomic with this DB transaction. No remote exactly-once guarantee; use a separate outbox if your application needs external effects.
- `payload` is stored as PostgreSQL JSONB and handed to the worker as `payload::text` (may be normalized); the original request SHA-256 is used for deduplication, **not** the original body bytes in handler.
- Use HTTPS, proper secrets, production HTTP infrastructure, process supervision, logging/monitoring, backups, encryption/retention controls as appropriate. The library does not include provider-specific integrations or these operational features.

[README and standalone Docker demo](../README.md) · [Architecture ADR](adr/0003-connection-contract.md) · [Security policy](../SECURITY.md).
