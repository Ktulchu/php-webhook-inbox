# Integrating PHP Webhook Inbox

All connection objects are created and configured by the hosting application. The library never calls `getenv()`, creates a PDO, registers a framework service or silently creates a new connection. Only PostgreSQL 16+ is supported.

Create the inbox schema by running [database/001_create_inbox.sql](../database/001_create_inbox.sql) using your project's migration runner before accepting events.

## Plain PHP with PDO

```php
use Ktulchu\WebhookInbox\Adapter\PdoAdapter;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Worker;

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$connection = new PdoAdapter($pdo);
$inbox = new Inbox($connection);
$worker = new Worker($connection, $yourHandler);
```

## Yii2

Install `yiisoft/yii2` in the host application. Reuse its configured `db` component, and **do not** extract its underlying PDO to control transactions:

```php
use Ktulchu\WebhookInbox\Adapter\Yii2Adapter;
use Ktulchu\WebhookInbox\Inbox;
use Ktulchu\WebhookInbox\Worker;

$connection = new Yii2Adapter(\Yii::$app->db);
$inbox = new Inbox($connection);
// In a yii\console\Controller::actionRun():
$worker = new Worker($connection, $yourHandler);
while (true) {
    if (!$worker->runOnce()) {
        sleep(1);
    }
}
```

Use the *same configured Yii2 PostgreSQL connection* in any handler's DB writes. If you choose ActiveRecord, it must use this exact Yii2 connection; otherwise prefer the passed `ConnectionInterface`. A worker cannot run while your Yii2 application has already begun a transaction on that connection.

## Symfony with Doctrine DBAL 4

Install `doctrine/dbal:^4` in the host project (typically through DoctrineBundle). Inject the existing `Doctrine\DBAL\Connection` service:

```php
use Doctrine\DBAL\Connection;
use Ktulchu\WebhookInbox\Adapter\DoctrineAdapter;
use Ktulchu\WebhookInbox\Worker;

// Inside a Symfony service/command whose constructor receives Connection $db:
$connection = new DoctrineAdapter($db);
$worker = new Worker($connection, $yourHandler);
$worker->runOnce();
```

Do not use DBAL's native driver connection to start/commit transactions. The adapter uses `Connection::beginTransaction()`, `commit()`, `rollBack()` and DBAL SQL APIs so that DBAL's transaction nesting level remains consistent. Use one DBAL connection for the event and business tables.

## Custom adapter

Implement `Ktulchu\WebhookInbox\ConnectionInterface` using your project's connection:

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

The contract requires associative rows, `null` for no row, named parameter binding, PostgreSQL savepoint SQL support and **one physical connection for all calls during a worker turn**. `fetchOne` must also support PostgreSQL `INSERT ... RETURNING`. `inTransaction` must detect active external transactions. Never pretend an isolated read/write or new connection is transactional with the worker.

### Webhook authentication

Validate provider authenticity and normalize the provider's event ID before `Inbox::receive(source, eventId, originalRawBody)`. Source identity must come from trusted server configuration, not an unchecked HTTP header. The built-in `Receiver` validates only the illustrative custom HMAC protocol and is not provider-agnostic.

### Operational boundaries

Use short handlers without remote side effects, retention and observability in production, and document your failure/retry strategy. Do not reuse the same connection across concurrent threads or async coroutines. Multiple processes must use separate physical connections.
