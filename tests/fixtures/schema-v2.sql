-- The schema-2 ledger as released before credit_log (db8de8b), used to test the 2 to 3 upgrade.
CREATE TABLE IF NOT EXISTS keys (
    id INTEGER PRIMARY KEY,
    ref TEXT NOT NULL UNIQUE,
    key_hash TEXT NOT NULL UNIQUE,
    balance INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL,
    -- per-key limits; NULL = the configured default
    max_concurrent INTEGER CHECK (max_concurrent IS NULL OR max_concurrent BETWEEN 1 AND 1000000000000000),
    requests_per_minute INTEGER CHECK (requests_per_minute IS NULL OR requests_per_minute BETWEEN 1 AND 1000000000000000),
    credits_per_minute INTEGER CHECK (credits_per_minute IS NULL OR credits_per_minute BETWEEN 1 AND 1000000000000000)
);
CREATE TABLE IF NOT EXISTS grants (
    grant_id TEXT PRIMARY KEY,
    key_id INTEGER NOT NULL REFERENCES keys(id),
    credits INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS leases (
    -- AUTOINCREMENT: a lease id is never reused, so a settle can only ever close its own lease
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_id INTEGER NOT NULL REFERENCES keys(id),
    reserved INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    settled INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS leases_open ON leases(key_id, settled, expires_at);
CREATE INDEX IF NOT EXISTS leases_window ON leases(key_id, created_at);
CREATE TABLE IF NOT EXISTS usage (
    id INTEGER PRIMARY KEY,
    ts INTEGER NOT NULL,
    key_id INTEGER NOT NULL REFERENCES keys(id),
    lease_id INTEGER NOT NULL UNIQUE REFERENCES leases(id),
    model TEXT NOT NULL,
    prompt_tokens INTEGER NOT NULL,
    completion_tokens INTEGER NOT NULL,
    cached_tokens INTEGER NOT NULL,
    -- credits
    reported INTEGER NOT NULL,
    charged INTEGER NOT NULL,
    http_status INTEGER NOT NULL,
    aborted INTEGER NOT NULL,
    estimated INTEGER NOT NULL,
    over_reservation INTEGER NOT NULL
);
PRAGMA user_version = 2;
