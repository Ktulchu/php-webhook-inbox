-- Demonstration of a transactionally consistent business side effect.
CREATE TABLE IF NOT EXISTS demo_effects (
    source VARCHAR(64) NOT NULL,
    event_id VARCHAR(200) NOT NULL,
    amount INTEGER NOT NULL,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (source, event_id)
);
