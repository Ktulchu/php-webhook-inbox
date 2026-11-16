# ADR 0001: PostgreSQL inbox rather than a message broker

Status: Accepted for MVP

## Context
Small applications already operate PostgreSQL, and adding a broker expands operational
surface area. We need durable admission, deduplication and safe concurrent claiming.

## Decision
Use a unique key on (source, event_id), a ready-time partial index, and transactional
row claims via SELECT ... FOR UPDATE SKIP LOCKED.

## Trade-offs
- One datastore and one transaction boundary; simple rollback and recovery.
- Row-level locks avoid duplicate concurrent handling.
- Polling adds latency, long transactions consume connections, and busy queues can
  stress PostgreSQL. SKIP LOCKED does not provide fairness or strict FIFO ordering.
- A dedicated queue/broker is more appropriate for high throughput, fan-out,
  complex routing or cross-service workloads.

## Revisit
Measure queue depth, processing latency, DB load and operational needs before
introducing Redis, RabbitMQ or Kafka.
