-- This example intentionally supports a single trusted provider ("demo").
-- Keep provider event IDs scoped to the authenticated source.
CREATE TABLE IF NOT EXISTS inbox_events (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source VARCHAR(64) NOT NULL,
    event_id VARCHAR(200) NOT NULL,
    payload JSONB NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'done', 'failed')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    received_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    available_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    processed_at TIMESTAMPTZ,
    last_error TEXT,
    CONSTRAINT inbox_source_event_unique UNIQUE (source, event_id)
);

CREATE INDEX IF NOT EXISTS inbox_pending_ready_idx
    ON inbox_events (available_at, id) WHERE status = 'pending';
