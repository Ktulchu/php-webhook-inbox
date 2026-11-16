# PHP Webhook Inbox

[![CI](https://github.com/Ktulchu/php-webhook-inbox/actions/workflows/ci.yml/badge.svg)](https://github.com/Ktulchu/php-webhook-inbox/actions/workflows/ci.yml)

**A small, framework-independent transactional webhook inbox for PHP 8.3+ and PostgreSQL 16+.**

Demonstrates durable ingestion, provider-scoped idempotency, concurrent workers, bounded retries and crash-safe *database-local* processing. Built as a deliberately constrained example: review the guarantees and limitations below before adapting it.

## Quick start

Requires Docker Compose and OpenSSL for the sample request.

```bash
git clone https://github.com/Ktulchu/php-webhook-inbox.git
cd php-webhook-inbox
cp .env.example .env
docker compose up --build -d
```

Generate a signed request (Linux/macOS shell):

```bash
# Must match WEBHOOK_SECRET in .env.
SECRET='replace-with-a-random-secret-of-at-least-32-bytes'
BODY='{"id":"evt-001","amount":125}'
STAMP=$(date +%s)
SIG=$(printf '%s.%s' "$STAMP" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')
curl -i -X POST http://127.0.0.1:8080/webhook \
  -H 'Content-Type: application/json' \
  -H "X-Webhook-Timestamp: $STAMP" \
  -H "X-Webhook-Signature: sha256=$SIG" \
  --data-binary "$BODY"
```

Expected response: HTTP **202** and `{"status":"accepted"}`. Re-send the *same exact bytes* with a fresh valid timestamp/signature: HTTP **200** and `{"status":"duplicate"}`. Reuse the event ID with a different body: HTTP **409**.

Process one event:

```bash
docker compose run --rm app php bin/worker.php --once
docker compose exec db psql -U webhooks -d webhooks \
  -c 'SELECT source, event_id, amount FROM demo_effects;'
```

Start long-running workers in parallel with `docker compose --profile worker up -d --scale worker=3 worker`. `docker compose down -v` **deletes local database data**.

### Local and CI tests

```bash
# With Docker Compose db/init already up; no worker profile enabled:
docker compose run --rm app composer test
docker compose run --rm app composer analyse
```

GitHub Actions runs PHPUnit against a real PostgreSQL 16 service and PHPStan. The tests exercise duplicate delivery, signature rejection, competing locks, handler rollback, retry exhaustion and process termination with an open transaction.

## Design

```text
provider -- signed POST --> receiver -- INSERT / UNIQUE --> inbox_events
                                                     |
                                 worker(s) <-- SKIP LOCKED
                                                     |
                                    single PostgreSQL transaction
                                    + SAVEPOINT around handler
                                                     |
                                   demo_effects + status=done
```

- **Authenticated source** is configured in `WEBHOOK_SOURCE`, not taken from an HTTP header. This example supports one signing secret. Adapt key rotation and multiple provider secrets explicitly when needed.
- **Verification** uses `HMAC-SHA256(timestamp . "." . rawBody)`; timestamp freshness is ±300 seconds. This is a demo protocol, **not** a drop-in adapter for Stripe, GitHub or other providers with different signature formats. Protect the secret and require HTTPS in deployment.
- **Ingress** inserts original JSON into PostgreSQL JSONB and stores a SHA-256 hash of the exact raw bytes. Provider-scoped `UNIQUE (source, event_id)` gates duplicates; same ID with different exact body bytes is a conflict. A 202 is returned only after the implicit INSERT transaction commits.
- **Processing** claims one ready row using `FOR UPDATE SKIP LOCKED`, keeps its row lock, executes the handler and marks `done` before **one** COMMIT. A dead process rolls back both effects and acknowledgment.
- **Failure** rolls back the handler to a savepoint while retaining the retry count and last error; default retry delays are 2, 4 seconds and then terminal `failed` on the third attempt. SQL errors thrown by a handler also count as failed attempts. If the DB connection itself fails, recording retry metadata is impossible: the transaction aborts and the event becomes available when the connection is released.
- **Diagnostics** retain events and failure details. This MVP does not implement automatic redrive of `failed` rows or retention cleanup; these are operator decisions.

## Guarantees and deliberate non-guarantees

| Situation | Behavior |
|---|---|
| Provider retries same ID and raw body | One stored event, no duplicate business effect |
| 3 workers compete for one ready event | One owns the row lock; other workers skip it |
| Handler throws after a DB write | Savepoint undoes its writes; retry remains pending |
| Process dies before COMMIT | PostgreSQL rolls back transaction; event is reclaimable |
| Handler and status commit | They commit atomically in the **same PostgreSQL database** |
| External HTTP call, email, broker publication | **Not exactly-once**. Never perform these in this demo handler |
| New payload reuses existing event ID | HTTP 409, no overwrite |
| PostgreSQL temporarily unavailable | Ingestion responds 503; sender must retry |

This is **at-least-once processing** with one committed effect for the included database-local demo handler, *not* a general distributed exactly-once platform. Repeated deliveries during the five-minute signature window are safe because durable event IDs, not timestamps, implement deduplication.

## Boundaries

- No Redis, RabbitMQ, workflow engine, framework, leasing system or external business effects.
- Payload limit 256 KiB. IDs must match `[A-Za-z0-9_.:-]{1,200}`; demo amount must be a positive integer <= 100,000,000.
- `--once` worker is useful for reproducible demonstrations; the default worker polls continuously every second when idle.
- DB credentials in `.env.example` are for an **isolated local environment only**.
- Schema creation is idempotent for a fresh deployment. There is intentionally no versioned migration runner.
- The PHP built-in web server is only for local demonstration; use a production HTTP server and secrets management for a real deployment.

## Architectural decisions

- [ADR 0001: PostgreSQL instead of a broker](docs/adr/0001-postgres-inbox.md)
- [ADR 0002: Transaction boundary](docs/adr/0002-transaction-boundary.md)

MIT license.

## Publication / deployment warning

This repository is a **reference implementation and local demo**, not a production-ready
Stripe/GitHub webhook adapter. Before deployment, use a provider-specific signature
protocol, HTTPS, random secrets with rotation, access controls, production HTTP server,
database backups, bounded handler execution time, structured operational logs and
monitoring of pending/failed events. Do not use sample credentials on any public host.

The request body is stored in plaintext JSONB in the example inbox. Do not feed real
customer personal data or secrets into the demo database without implementing
appropriate retention, encryption, access restrictions and deletion policies.
