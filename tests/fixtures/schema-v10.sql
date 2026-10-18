-- The schema-10 ledger as released in v0.9.1 to v0.12.1, used to test the 10 to 11 upgrade.
CREATE TABLE keys (
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
, bonus_pct INTEGER NOT NULL DEFAULT 0 CHECK (bonus_pct BETWEEN 0 AND 300), key_group TEXT NOT NULL DEFAULT '', removed_at INTEGER);
CREATE TABLE grants (
    grant_id TEXT PRIMARY KEY,
    key_id INTEGER NOT NULL REFERENCES keys(id),
    credits INTEGER NOT NULL,
    created_at INTEGER NOT NULL
, expires_at INTEGER);
CREATE TABLE leases (
    -- AUTOINCREMENT: a lease id is never reused, so a settle can only ever close its own lease
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_id INTEGER NOT NULL REFERENCES keys(id),
    reserved INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    settled INTEGER NOT NULL DEFAULT 0
, source INTEGER NOT NULL DEFAULT 0 CHECK (source IN (0, 1)), cost_reserved INTEGER NOT NULL DEFAULT 0);
CREATE INDEX leases_open ON leases(key_id, settled, expires_at);
CREATE INDEX leases_window ON leases(key_id, created_at);
CREATE TABLE usage (
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
, upstream TEXT NOT NULL DEFAULT '', stream INTEGER NOT NULL DEFAULT 0, duration_ms INTEGER NOT NULL DEFAULT 0, reasoning_tokens INTEGER NOT NULL DEFAULT 0, upstream_cost INTEGER, delay_ms INTEGER NOT NULL DEFAULT 0, source INTEGER NOT NULL DEFAULT 0 CHECK (source IN (0, 1)), cost_computed INTEGER NOT NULL DEFAULT 0 CHECK (cost_computed IN (0, 1)), first_byte_ms INTEGER, attempts INTEGER NOT NULL DEFAULT 0);
CREATE TABLE credit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ts INTEGER NOT NULL,
    key_id INTEGER NOT NULL REFERENCES keys(id),
    kind TEXT NOT NULL CHECK (kind IN ('opening', 'grant', 'reserve', 'refund')),
    -- credits added (positive) or removed (negative), and the balance right after
    delta INTEGER NOT NULL CHECK (delta != 0),
    balance_after INTEGER NOT NULL,
    -- the cause: the grant id, 'lease:<id>', or 'schema-<n>' for an opening balance
    ref TEXT NOT NULL
);
CREATE INDEX usage_ts ON usage(ts);
CREATE INDEX credit_log_key ON credit_log(key_id, id);
CREATE TRIGGER credit_log_no_update BEFORE UPDATE ON credit_log
    BEGIN SELECT RAISE(ABORT, 'credit_log is append-only'); END;
CREATE TRIGGER credit_log_no_delete BEFORE DELETE ON credit_log
    BEGIN SELECT RAISE(ABORT, 'credit_log is append-only'); END;
CREATE TRIGGER credit_log_no_replace BEFORE INSERT ON credit_log
    WHEN EXISTS (SELECT 1 FROM credit_log WHERE id = NEW.id)
    BEGIN SELECT RAISE(ABORT, 'credit_log is append-only'); END;
CREATE INDEX grants_key ON grants(key_id);
CREATE INDEX leases_source_open ON leases(source, settled, expires_at);
CREATE INDEX leases_created ON leases(created_at);
CREATE INDEX usage_key ON usage(key_id, ts);
CREATE TABLE spend_hourly (hour INTEGER PRIMARY KEY, charged INTEGER NOT NULL, cost INTEGER NOT NULL DEFAULT 0);
PRAGMA user_version = 10;
