<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The credit ledger: keys with one never-resetting balance each, idempotent grants,
 * per-request leases (reserve -> settle), per-key limits and a metadata-only usage log.
 *
 * Every balance change is a short BEGIN IMMEDIATE transaction; creating, rotating and enabling
 * a key are single statements. Nothing is held open while a
 * request streams, so a slow client never blocks other keys.
 *
 * A lease's reservation is taken from the balance when the request starts and stays
 * taken until the request is settled. A lease that outlives its request (its worker
 * died) stops counting against concurrency when it expires, but its reservation stays
 * charged: the request may already have been answered. A late settle still corrects it.
 *
 * Every change to a balance writes one credit_log row in the same transaction: the change, the
 * balance after it, its kind (grant, reserve, refund, or the opening balance carried over by a
 * schema upgrade) and what caused it (the grant id or the lease). The table is append-only:
 * triggers refuse UPDATE and DELETE. For every key, the deltas sum to the balance, and each row's
 * balance_after is the running sum up to it; verify() checks both.
 */
final class Ledger
{
    /** Largest grant accepted in one call, and the largest balance magnitude kept. Far inside int64. */
    public const MAX_GRANT = 1_000_000_000_000_000;
    private const MAX_BALANCE = 1_000_000_000_000_000_000;
    private const SCHEMA_VERSION = 7;
    /** Older schemas migrate() upgrades in place: 2 gains credit_log, 2 to 6 gain the columns below. */
    private const UPGRADABLE_FROM = [2, 3, 4, 5, 6];
    /**
     * Columns added after the first schema, per table. Schema 4, usage, for tracking over time: the upstream that
     * served the request (the host of its route URL), whether it streamed, how long it took, the reasoning share of
     * the output tokens, and the cost the upstream itself reported, in millionths of its unit (NULL when it reports
     * none). Schema 5, for throttling and priority: a key's bonus percent (part of its weight), whether a lease came
     * from an internal caller (each source has its own busy line), and how long a request was slowed down. Schema 6, for
     * cost tracking: the source of each usage row, whether its cost was computed from configured prices because the
     * upstream reported none, and a key's group (an operator-chosen name such as a plan or customer group). Schema 7, for
     * the spend caps: the upper bound of a lease's upstream cost, held against the caps while the request runs
     * (spend_hourly gains the settled upstream cost per hour as well).
     */
    private const ADDED_COLUMNS = [
        'usage' => ['upstream' => "TEXT NOT NULL DEFAULT ''", 'stream' => 'INTEGER NOT NULL DEFAULT 0',
            'duration_ms' => 'INTEGER NOT NULL DEFAULT 0', 'reasoning_tokens' => 'INTEGER NOT NULL DEFAULT 0', 'upstream_cost' => 'INTEGER',
            'delay_ms' => 'INTEGER NOT NULL DEFAULT 0', 'source' => 'INTEGER NOT NULL DEFAULT 0 CHECK (source IN (0, 1))',
            'cost_computed' => 'INTEGER NOT NULL DEFAULT 0 CHECK (cost_computed IN (0, 1))'],
        'keys' => ['bonus_pct' => 'INTEGER NOT NULL DEFAULT 0 CHECK (bonus_pct BETWEEN 0 AND 300)', 'key_group' => "TEXT NOT NULL DEFAULT ''"],
        'leases' => ['source' => 'INTEGER NOT NULL DEFAULT 0 CHECK (source IN (0, 1))', 'cost_reserved' => 'INTEGER NOT NULL DEFAULT 0'],
    ];
    /** The largest bonus percent a key can carry (also the CHECK in ADDED_COLUMNS). */
    public const MAX_BONUS_PCT = 300;
    /** stats(): time bucket name => its SQL expression, and grouping name => its column. Only these reach SQL. */
    private const STATS_BUCKETS = ['hour' => "strftime('%Y-%m-%dT%H', u.ts, 'unixepoch')", 'day' => "strftime('%Y-%m-%d', u.ts, 'unixepoch')",
        'week' => "strftime('%Y-W%W', u.ts, 'unixepoch')", 'month' => "strftime('%Y-%m', u.ts, 'unixepoch')", 'all' => "'all'",
        'hour_of_day' => "strftime('%H', u.ts, 'unixepoch')", 'weekday' => "strftime('%w', u.ts, 'unixepoch')"];
    private const STATS_GROUPS = ['model' => 'u.model', 'upstream' => 'u.upstream', 'key' => 'k.ref', 'status' => 'u.http_status',
        'source' => "CASE u.source WHEN 1 THEN 'internal' ELSE 'external' END", 'group' => 'k.key_group'];
    /** A key group: empty (none) or up to 64 of a-z, 0-9, dot, dash and underscore. */
    private const GROUP_PATTERN = '/^[a-z0-9._-]{0,64}$/';
    /** Per-key limits: the name used by the admin API and CLI => its column. Only these names reach SQL. */
    private const LIMITS = ['max_concurrent' => 'max_concurrent', 'requests_per_minute' => 'requests_per_minute', 'credits_per_minute' => 'credits_per_minute'];
    private const WINDOW_SECONDS = 60;

    private \PDO $db;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (is_dir($dir) && (fileperms($dir) & 0022) !== 0) {
            throw new \RuntimeException("ledger directory $dir is writable by group or others");
        }
        foreach ([$path, "$path-wal", "$path-shm"] as $f) {
            if (is_file($f) && (fileperms($f) & 0077) !== 0) {
                throw new \RuntimeException("ledger file $f is accessible to other users; chmod 600 it");
            }
        }
        // A new database is created private whatever the caller's umask; SQLite creates its -wal
        // and -shm files with the database file's permissions, so they stay private too.
        $umask = umask(0077);
        try {
            $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->db->exec('PRAGMA busy_timeout = 10000');
            $this->db->exec('PRAGMA journal_mode = WAL');
            $this->db->exec('PRAGMA foreign_keys = ON');
        } finally {
            umask($umask);
        }
    }

    /**
     * Creates the schema, or upgrades an older ledger in place in one transaction. From schema 2,
     * credit_log is added and each key with a nonzero balance gets one 'opening' row carrying that
     * balance, so the sum check holds from the first moment. From schema 2 to 4, the tables gain the
     * added columns they lack (ADDED_COLUMNS); existing rows keep their values and get the columns'
     * defaults (bonus 0, external source, no delay). Any other older or newer schema is refused.
     */
    public function migrate(): void
    {
        $version = (int) $this->db->query('PRAGMA user_version')->fetchColumn();
        $tables = (int) $this->db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'keys'")->fetchColumn();
        if ($tables > 0 && $version !== self::SCHEMA_VERSION && !in_array($version, self::UPGRADABLE_FROM, true)) {
            throw new \RuntimeException('this ledger was created by another version of virsilipas (schema ' . $version . ', expected ' . self::SCHEMA_VERSION . '); start a new ledger');
        }
        $openingRows = $tables > 0 && $version === 2;
        $this->tx(function () use ($openingRows): void {
            $this->createSchema();
            if ($openingRows) {
                $this->db->prepare("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) SELECT ?, id, 'opening', balance, balance, 'schema-3' FROM keys WHERE balance != 0 ORDER BY id")
                    ->execute([time()]);
            }
            // One definition for new and upgraded ledgers alike: whatever added column is missing is added.
            foreach (self::ADDED_COLUMNS as $table => $columns) {
                $have = array_column($this->db->query("PRAGMA table_info($table)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
                foreach ($columns as $name => $type) {
                    if (!in_array($name, $have, true)) {
                        $this->db->exec("ALTER TABLE $table ADD COLUMN $name $type");
                    }
                }
            }
            $this->db->exec('CREATE INDEX IF NOT EXISTS leases_source_open ON leases(source, settled, expires_at)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS leases_created ON leases(created_at)');
            // Credits charged and upstream cost per UTC hour, kept by settle, so spend pacing and the spend caps read at
            // most one row per hour of their window however busy it was. Both are rebuilt from the usage rows when added.
            $newHourly = (int) $this->db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'spend_hourly'")->fetchColumn() === 0;
            $this->db->exec('CREATE TABLE IF NOT EXISTS spend_hourly (hour INTEGER PRIMARY KEY, charged INTEGER NOT NULL, cost INTEGER NOT NULL DEFAULT 0)');
            if ($newHourly) {
                $this->db->exec('INSERT INTO spend_hourly (hour, charged, cost) SELECT ts / 3600, SUM(charged), COALESCE(SUM(upstream_cost), 0) FROM usage GROUP BY ts / 3600');
            } elseif (!in_array('cost', array_column($this->db->query('PRAGMA table_info(spend_hourly)')->fetchAll(\PDO::FETCH_ASSOC), 'name'), true)) {
                $this->db->exec('ALTER TABLE spend_hourly ADD COLUMN cost INTEGER NOT NULL DEFAULT 0');
                $this->db->exec('UPDATE spend_hourly SET cost = COALESCE((SELECT SUM(u.upstream_cost) FROM usage u WHERE u.ts >= spend_hourly.hour * 3600 AND u.ts < spend_hourly.hour * 3600 + 3600), 0)');
            }
            $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        });
    }

    private function createSchema(): void
    {
        $this->db->exec(<<<'SQL'
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
            CREATE TABLE IF NOT EXISTS credit_log (
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
            CREATE INDEX IF NOT EXISTS usage_ts ON usage(ts);
            CREATE INDEX IF NOT EXISTS credit_log_key ON credit_log(key_id, id);
            CREATE TRIGGER IF NOT EXISTS credit_log_no_update BEFORE UPDATE ON credit_log
                BEGIN SELECT RAISE(ABORT, 'credit_log is append-only'); END;
            CREATE TRIGGER IF NOT EXISTS credit_log_no_delete BEFORE DELETE ON credit_log
                BEGIN SELECT RAISE(ABORT, 'credit_log is append-only'); END;
            SQL);
    }

    /**
     * Creates a key for a ref: the billing system's stable id for the USER, so that all of a
     * user's purchases add to one balance. Returns the plaintext key, shown once.
     */
    public function createKey(string $ref): string
    {
        $key = 'vsk-' . bin2hex(random_bytes(24));
        try {
            $this->db->prepare('INSERT INTO keys (ref, key_hash, created_at) VALUES (?, ?, ?)')
                ->execute([$ref, hash('sha256', $key), time()]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e; // only a constraint violation means "already exists"
            }
            throw new HttpError(409, 'a key already exists for this ref');
        }
        return $key;
    }

    /**
     * Replaces a ref's key with a new one (a leaked key): the old key stops working at once; the balance,
     * limits, credit log and usage stay with the ref. Returns the new plaintext key, shown once.
     */
    public function rotateKey(string $ref): string
    {
        $key = 'vsk-' . bin2hex(random_bytes(24));
        $st = $this->db->prepare('UPDATE keys SET key_hash = ? WHERE ref = ?');
        $st->execute([hash('sha256', $key), $ref]);
        if ($st->rowCount() !== 1) {
            throw new HttpError(404, 'unknown ref');
        }
        return $key;
    }

    public function setActive(string $ref, bool $active): void
    {
        $st = $this->db->prepare('UPDATE keys SET active = ? WHERE ref = ?');
        $st->execute([$active ? 1 : 0, $ref]);
        if ($st->rowCount() !== 1) {
            throw new HttpError(404, 'unknown ref');
        }
    }

    /**
     * Applies a billing grant once per grant id. Credits may be negative (refund or chargeback).
     * Retrying with the same grant id and the same ref and amount is a no-op; reusing a grant id
     * for anything else is a conflict.
     * @return array{applied: bool, balance: int}
     */
    public function grant(string $ref, string $grantId, int $credits): array
    {
        if ($credits === 0 || abs($credits) > self::MAX_GRANT || $grantId === '') {
            throw new HttpError(400, 'grant needs a grant_id and nonzero credits of at most ' . self::MAX_GRANT);
        }
        return $this->tx(function () use ($ref, $grantId, $credits): array {
            $keyId = $this->keyIdByRef($ref);
            $st = $this->db->prepare('SELECT key_id, credits FROM grants WHERE grant_id = ?');
            $st->execute([$grantId]);
            $prior = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($prior !== false) {
                if ((int) $prior['key_id'] !== $keyId || (int) $prior['credits'] !== $credits) {
                    throw new HttpError(409, 'grant_id was already used for a different ref or amount');
                }
                return ['applied' => false, 'balance' => $this->balanceOf($keyId)];
            }
            $new = $this->balanceOf($keyId) + $credits;
            if (abs($new) > self::MAX_BALANCE) {
                throw new HttpError(400, 'grant would take the balance out of range');
            }
            $this->db->prepare('INSERT INTO grants (grant_id, key_id, credits, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$grantId, $keyId, $credits, time()]);
            $this->db->prepare('UPDATE keys SET balance = balance + ? WHERE id = ?')->execute([$credits, $keyId]);
            $this->logCredit($keyId, 'grant', $credits, $grantId);
            return ['applied' => true, 'balance' => $new];
        });
    }

    /** @return array{id: int, ref: string, balance: int, active: bool, bonus_pct: int}|null */
    public function findKey(string $plainKey): ?array
    {
        $st = $this->db->prepare('SELECT id, ref, balance, active, bonus_pct FROM keys WHERE key_hash = ?');
        $st->execute([hash('sha256', $plainKey)]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        if ($row === false) {
            return null;
        }
        return ['id' => (int) $row['id'], 'ref' => $row['ref'], 'balance' => (int) $row['balance'], 'active' => (bool) $row['active'],
            'bonus_pct' => (int) $row['bonus_pct']];
    }

    /** Sets a key's bonus percent (0 to MAX_BONUS_PCT), one of the two inputs of its priority weight. */
    public function setBonus(string $ref, int $pct): int
    {
        if ($pct < 0 || $pct > self::MAX_BONUS_PCT) {
            throw new HttpError(400, 'bonus_pct must be an integer from 0 to ' . self::MAX_BONUS_PCT);
        }
        return $this->tx(function () use ($ref, $pct): int {
            $this->db->prepare('UPDATE keys SET bonus_pct = ? WHERE id = ?')->execute([$pct, $this->keyIdByRef($ref)]);
            return $pct;
        });
    }

    /** Whether a string can be a key group. */
    public static function validGroup(mixed $group): bool
    {
        return is_string($group) && preg_match(self::GROUP_PATTERN, $group) === 1;
    }

    /** Sets a key's group (empty for none), the `group` dimension of stats. */
    public function setGroup(string $ref, string $group): string
    {
        if (!self::validGroup($group)) {
            throw new HttpError(400, 'key_group must be at most 64 of a-z, 0-9, dot, dash and underscore');
        }
        return $this->tx(function () use ($ref, $group): string {
            $this->db->prepare('UPDATE keys SET key_group = ? WHERE id = ?')->execute([$group, $this->keyIdByRef($ref)]);
            return $group;
        });
    }

    /** Credits charged by all keys since the start of the UTC hour that holds a unix time (so up to an hour more than asked): the spend the throttle paces against its budget. */
    public function chargedSince(int $since): int
    {
        $st = $this->db->prepare('SELECT COALESCE(SUM(charged), 0) FROM spend_hourly WHERE hour >= ?');
        $st->execute([intdiv($since, 3600)]);
        $n = (int) $st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /** Requests in flight from one source (1 = internal callers, 0 = everyone else), across all keys. */
    public function openLeases(int $source): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM leases WHERE source = ? AND settled = 0 AND expires_at > ?');
        $st->execute([$source, time()]);
        $n = (int) $st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /**
     * Balance, holds, limits and recent use. `reserved` = credits held by unsettled leases (already
     * taken from the balance); `held` = expired leases never settled (their worker died), whose
     * reservations stay charged so the operator can see and reconcile them. `window` = requests and
     * credits (charged, or still reserved) over the last 60 seconds.
     * @param array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int} $defaults
     * @return array{ref: string, balance: int, active: bool, in_flight: int, held: int, reserved: int,
     *     overrides: array<string, ?int>, limits: array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int},
     *     window: array{requests: int, credits: int}, bonus_pct: int, key_group: string}
     */
    public function status(string $ref, array $defaults): array
    {
        $now = time();
        $st = $this->db->prepare('SELECT k.id, k.ref, k.balance, k.active, k.bonus_pct, k.key_group, k.max_concurrent, k.requests_per_minute, k.credits_per_minute,
                (SELECT COUNT(*) FROM leases l WHERE l.key_id = k.id AND l.settled = 0 AND l.expires_at > ?) AS in_flight,
                (SELECT COUNT(*) FROM leases l WHERE l.key_id = k.id AND l.settled = 0 AND l.expires_at <= ?) AS held,
                (SELECT COALESCE(SUM(l.reserved), 0) FROM leases l WHERE l.key_id = k.id AND l.settled = 0) AS reserved
            FROM keys k WHERE k.ref = ?');
        $st->execute([$now, $now, $ref]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        if ($row === false) {
            throw new HttpError(404, 'unknown ref');
        }
        $w = $this->window((int) $row['id'], $now);
        $overrides = self::overrides($row);
        return ['ref' => $row['ref'], 'balance' => (int) $row['balance'], 'active' => (bool) $row['active'],
            'in_flight' => (int) $row['in_flight'], 'held' => (int) $row['held'], 'reserved' => (int) $row['reserved'],
            'overrides' => $overrides, 'limits' => self::effectiveLimits($overrides, $defaults),
            'window' => ['requests' => $w['requests'], 'credits' => $w['credits']], 'bonus_pct' => (int) $row['bonus_pct'], 'key_group' => (string) $row['key_group']];
    }

    /**
     * Sets a key's own limits. Each given name is set to a positive integer, or to null to follow the
     * configured default again; names not given are left as they are.
     * @param array<string, mixed> $changes
     * @return array<string, ?int> the key's overrides after the change
     */
    public function setLimits(string $ref, array $changes): array
    {
        $sets = [];
        $values = [];
        foreach ($changes as $name => $value) {
            if (!is_string($name) || !isset(self::LIMITS[$name])) {
                throw new HttpError(400, 'unknown limit; use ' . implode(', ', array_keys(self::LIMITS)));
            }
            if ($value !== null && (!is_int($value) || $value < 1 || $value > self::MAX_GRANT)) {
                throw new HttpError(400, "$name must be null (the default) or an integer from 1 to " . self::MAX_GRANT);
            }
            $sets[] = self::LIMITS[$name] . ' = ?';
            $values[] = $value;
        }
        return $this->tx(function () use ($ref, $sets, $values): array {
            $keyId = $this->keyIdByRef($ref);
            if ($sets !== []) {
                $this->db->prepare('UPDATE keys SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute([...$values, $keyId]);
            }
            $st = $this->db->prepare('SELECT ' . implode(', ', self::LIMITS) . ' FROM keys WHERE id = ?');
            $st->execute([$keyId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            return self::overrides(is_array($row) ? $row : []);
        });
    }

    /**
     * The limits in force: the key's own setting where it has one, else the configured default.
     * @param array<string, ?int> $overrides
     * @param array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int} $defaults
     * @return array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int}
     */
    public static function effectiveLimits(array $overrides, array $defaults): array
    {
        return ['max_concurrent' => $overrides['max_concurrent'] ?? $defaults['max_concurrent'],
            'requests_per_minute' => $overrides['requests_per_minute'] ?? $defaults['requests_per_minute'],
            'credits_per_minute' => $overrides['credits_per_minute'] ?? $defaults['credits_per_minute']];
    }

    /**
     * Reserves credits for one request: the prompt bound at the input rate plus max_tokens at the
     * output rate. max_tokens is clamped so the reservation never exceeds the balance. Refusals, in
     * order: disabled key, concurrency, requests per minute, credits per minute, the server's requests per
     * minute, balance, the spend caps.
     * A spend cap holds the request's upper-bound upstream cost (its prompt bound and max_tokens at $costRates) against
     * the window's settled cost plus the bounds of the requests still running that started in it; a request that does
     * not fit is refused, never shortened, so no one pays for an answer cut short by the server's budget.
     * @param array{input: int, cached_input: int, output: int} $rates
     * @param array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int} $defaults
     * @param array{spend?: array<string, ?int>, requests_per_minute?: ?int} $caps Config::$caps
     * @param ?array{input: int, cached_input: int, output: int} $costRates the alias's Config::$costBounds entry
     * @return array{lease_id: int, max_tokens: int, reserved: int}
     */
    public function reserve(int $keyId, int $promptBound, ?int $requestedMax, int $cap, array $rates, array $defaults, int $leaseSeconds, int $source = 0,
        array $caps = [], ?array $costRates = null): array
    {
        return $this->tx(function () use ($keyId, $promptBound, $requestedMax, $cap, $rates, $defaults, $leaseSeconds, $source, $caps, $costRates): array {
            $now = time();
            $st = $this->db->prepare('SELECT balance, active, ' . implode(', ', self::LIMITS) . ' FROM keys WHERE id = ?');
            $st->execute([$keyId]);
            $k = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($k === false || !(int) $k['active']) {
                throw new HttpError(403, 'key is disabled', 'permission_error', code: 'key_disabled');
            }
            $limits = self::effectiveLimits(self::overrides($k), $defaults);

            $st = $this->db->prepare('SELECT COUNT(*) FROM leases WHERE key_id = ? AND settled = 0 AND expires_at > ?');
            $st->execute([$keyId, $now]);
            if ((int) $st->fetchColumn() >= $limits['max_concurrent']) {
                throw new HttpError(429, 'too many concurrent requests for this key', 'rate_limit_error', 1, 'rate_limit_exceeded');
            }

            if ($limits['requests_per_minute'] !== null || $limits['credits_per_minute'] !== null) {
                $w = $this->window($keyId, $now);
                // The earliest moment the window can free: when its oldest request turns 60 seconds old.
                $retry = max(1, $w['oldest'] + self::WINDOW_SECONDS - $now);
                if ($limits['requests_per_minute'] !== null && $w['requests'] >= $limits['requests_per_minute']) {
                    throw new HttpError(429, 'requests per minute limit reached for this key', 'rate_limit_error', $retry, 'rate_limit_exceeded');
                }
                if ($limits['credits_per_minute'] !== null && $w['credits'] >= $limits['credits_per_minute']) {
                    throw new HttpError(429, 'credits per minute limit reached for this key', 'rate_limit_error', $retry, 'rate_limit_exceeded');
                }
            }

            $serverRpm = $caps['requests_per_minute'] ?? null;
            if ($serverRpm !== null) {
                $st = $this->db->prepare('SELECT COUNT(*), COALESCE(MIN(created_at), ?) FROM leases WHERE created_at > ?');
                $st->execute([$now, $now - self::WINDOW_SECONDS]);
                [$started, $oldest] = array_map('intval', $st->fetch(\PDO::FETCH_NUM));
                $st->closeCursor();
                if ($started >= $serverRpm) {
                    throw new HttpError(429, 'the server is at its request rate limit; retry shortly', 'rate_limit_error',
                        max(1, $oldest + self::WINDOW_SECONDS - $now), 'server_rate_limit');
                }
            }

            $maxTokens = min($requestedMax ?? $cap, Credits::affordable((int) $k['balance'], $promptBound, $cap, $rates));
            if ($maxTokens < 1) {
                throw new HttpError(402, 'credit balance too low for this request', 'insufficient_quota', code: 'insufficient_quota');
            }
            // Priced like a charge, rounded once: any reported usage within the prompt bound and max_tokens costs no more.
            $reserved = Credits::of($promptBound, 0, $maxTokens, $rates);

            $costBound = $costRates === null ? 0 : Credits::of($promptBound, 0, $maxTokens, $costRates);
            foreach ($caps['spend'] ?? [] as $window => $limit) {
                if ($limit === null) {
                    continue;
                }
                [$start, $end] = self::capWindow($window, $now);
                if ($limit === 0 || $this->costSince($start) + $this->costInFlight($start) + $costBound > $limit) {
                    throw new HttpError(503, "the server has reached its spending limit for this $window", 'server_error', max(1, $end - $now), 'spend_cap_reached');
                }
            }

            $upd = $this->db->prepare('UPDATE keys SET balance = balance - ? WHERE id = ? AND balance >= ?');
            $upd->execute([$reserved, $keyId, $reserved]);
            if ($upd->rowCount() !== 1) {
                throw new HttpError(402, 'credit balance too low for this request', 'insufficient_quota', code: 'insufficient_quota');
            }
            $this->db->prepare('INSERT INTO leases (key_id, reserved, created_at, expires_at, source, cost_reserved) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$keyId, $reserved, $now, $now + $leaseSeconds, $source === 1 ? 1 : 0, $costBound]);
            $leaseId = (int) $this->db->lastInsertId();
            $this->logCredit($keyId, 'reserve', -$reserved, 'lease:' . $leaseId);

            return ['lease_id' => $leaseId, 'max_tokens' => $maxTokens, 'reserved' => $reserved];
        });
    }

    /**
     * Settles a request exactly once: charges the credits the request used, never more than the
     * reservation, returns the rest, and writes the usage row in the same transaction.
     * Settling an already settled lease does nothing and returns false.
     * The tracking fields (upstream, stream, duration_ms, reasoning, cost) are optional and default to empty.
     * @param array{model: string, prompt: int, completion: int, cached: int, status: int, aborted: bool, estimated: bool, upstream?: string, stream?: bool, duration_ms?: int, reasoning?: int, cost?: ?int, delay_ms?: int, cost_computed?: bool} $meta
     */
    public function settle(int $leaseId, int $keyId, int $used, array $meta): bool
    {
        return $this->tx(function () use ($leaseId, $keyId, $used, $meta): bool {
            $st = $this->db->prepare('SELECT reserved, source FROM leases WHERE id = ? AND key_id = ? AND settled = 0');
            $st->execute([$leaseId, $keyId]);
            $lease = $st->fetch(\PDO::FETCH_NUM);
            $st->closeCursor();
            if ($lease === false) {
                return false;
            }
            [$reserved, $source] = [(int) $lease[0], (int) $lease[1]];
            $reserved = (int) $reserved;
            $used = max(0, $used);
            $charged = min($used, $reserved);
            $this->db->prepare('UPDATE leases SET settled = 1 WHERE id = ?')->execute([$leaseId]);
            $this->db->prepare('UPDATE keys SET balance = balance + ? WHERE id = ?')->execute([$reserved - $charged, $keyId]);
            $this->logCredit($keyId, 'refund', $reserved - $charged, 'lease:' . $leaseId);
            $now = time();
            $this->db->prepare('INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation, upstream, stream, duration_ms, reasoning_tokens, upstream_cost, delay_ms, source, cost_computed) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$now, $keyId, $leaseId, $meta['model'], $meta['prompt'], $meta['completion'], $meta['cached'], $used, $charged, $meta['status'], (int) $meta['aborted'], (int) $meta['estimated'], (int) ($used > $reserved),
                    $meta['upstream'] ?? '', (int) ($meta['stream'] ?? false), max(0, $meta['duration_ms'] ?? 0), $meta['reasoning'] ?? 0, $meta['cost'] ?? null, max(0, $meta['delay_ms'] ?? 0), $source, (int) ($meta['cost_computed'] ?? false)]);
            $this->db->prepare('INSERT INTO spend_hourly (hour, charged, cost) VALUES (?, ?, ?) ON CONFLICT(hour) DO UPDATE SET charged = charged + excluded.charged, cost = cost + excluded.cost')
                ->execute([intdiv($now, 3600), $charged, max(0, $meta['cost'] ?? 0)]);
            return true;
        });
    }

    /** UTC start and end (unix seconds) of the hour, day or month holding $now: a spend cap's window. */
    public static function capWindow(string $window, int $now): array
    {
        return match ($window) {
            'hour' => [$now - $now % 3600, $now - $now % 3600 + 3600],
            'day' => [$now - $now % 86400, $now - $now % 86400 + 86400],
            'month' => [gmmktime(0, 0, 0, (int) gmdate('n', $now), 1, (int) gmdate('Y', $now)), gmmktime(0, 0, 0, (int) gmdate('n', $now) + 1, 1, (int) gmdate('Y', $now))],
            default => throw new \InvalidArgumentException("unknown cap window $window"),
        };
    }

    /** Upstream cost settled since $start (a whole UTC hour), in millionths of the upstream unit. */
    public function costSince(int $start): int
    {
        $st = $this->db->prepare('SELECT COALESCE(SUM(cost), 0) FROM spend_hourly WHERE hour >= ?');
        $st->execute([intdiv($start, 3600)]);
        $n = (int) $st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /** The cost bounds of requests started since $start and not settled yet, a dead worker's included. */
    private function costInFlight(int $start): int
    {
        $st = $this->db->prepare('SELECT COALESCE(SUM(cost_reserved), 0) FROM leases WHERE settled = 0 AND created_at >= ?');
        $st->execute([$start]);
        $n = (int) $st->fetchColumn();
        $st->closeCursor();
        return $n;
    }

    /**
     * Each configured cap with its use now: spend windows (limit, settled, in flight, remaining, reset time) and the
     * server's requests over the last 60 seconds.
     * @param array{spend: array<string, ?int>, requests_per_minute: ?int} $caps Config::$caps
     * @return array<string, array<string, int>>
     */
    public function capStatus(array $caps): array
    {
        $now = time();
        $out = [];
        foreach ($caps['spend'] as $window => $limit) {
            if ($limit === null) {
                continue;
            }
            [$start, $end] = self::capWindow($window, $now);
            [$settled, $inFlight] = [$this->costSince($start), $this->costInFlight($start)];
            $out[$window] = ['limit' => $limit, 'settled' => $settled, 'in_flight' => $inFlight, 'remaining' => max(0, $limit - $settled - $inFlight), 'resets_at' => $end];
        }
        if ($caps['requests_per_minute'] !== null) {
            $st = $this->db->prepare('SELECT COUNT(*) FROM leases WHERE created_at > ?');
            $st->execute([$now - self::WINDOW_SECONDS]);
            $out['requests_per_minute'] = ['limit' => $caps['requests_per_minute'], 'last_minute' => (int) $st->fetchColumn()];
            $st->closeCursor();
        }
        return $out;
    }

    /**
     * Usage over time, from the usage rows: one row per time bucket (UTC hour, day or month, or 'all') and
     * per value of each grouping asked for (model alias, upstream, key ref, upstream HTTP status), oldest
     * first, with request counts, token sums, credits charged, the upstream's reported cost (and how many
     * requests carried one), hang-ups, estimated charges, failed requests and the mean duration.
     * @param list<string> $groups any of model, upstream, key, status
     * @return list<array<string, int|string|null>>
     */
    public function stats(string $bucket, array $groups = [], ?int $since = null, ?int $until = null): array
    {
        if (!isset(self::STATS_BUCKETS[$bucket])) {
            throw new \InvalidArgumentException('bucket must be one of ' . implode(', ', array_keys(self::STATS_BUCKETS)));
        }
        $cols = [];
        foreach (array_unique($groups) as $g) {
            if (!isset(self::STATS_GROUPS[$g])) {
                throw new \InvalidArgumentException('group by any of ' . implode(', ', array_keys(self::STATS_GROUPS)));
            }
            $cols[$g] = self::STATS_GROUPS[$g];
        }
        $select = self::STATS_BUCKETS[$bucket] . ' AS bucket';
        foreach ($cols as $g => $col) {
            $select .= ", $col AS \"$g\"";
        }
        $group = implode(', ', ['bucket', ...array_map(static fn (string $g): string => "\"$g\"", array_keys($cols))]);
        $st = $this->db->prepare("SELECT $select, COUNT(*) AS requests, SUM(u.prompt_tokens) AS prompt_tokens,
                SUM(u.cached_tokens) AS cached_tokens, SUM(u.completion_tokens) AS completion_tokens,
                SUM(u.reasoning_tokens) AS reasoning_tokens, SUM(u.charged) AS credits, SUM(u.upstream_cost) AS upstream_cost,
                COUNT(u.upstream_cost) AS costed_requests, SUM(u.cost_computed) AS computed_cost_requests, SUM(u.aborted) AS aborted, SUM(u.estimated) AS estimated,
                SUM(u.http_status NOT BETWEEN 200 AND 299) AS failed, CAST(AVG(u.duration_ms) AS INTEGER) AS mean_duration_ms,
                SUM(u.delay_ms > 0) AS delayed, CAST(AVG(u.delay_ms) AS INTEGER) AS mean_delay_ms
            FROM usage u JOIN keys k ON k.id = u.key_id WHERE u.ts >= ? AND u.ts < ? GROUP BY $group ORDER BY $group");
        $st->execute([$since ?? 0, $until ?? PHP_INT_MAX]);
        $rows = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            foreach ($r as $name => $v) {
                $r[$name] = ($v === null || $name === 'bucket' || in_array($name, ['model', 'upstream', 'key', 'source', 'group'], true)) ? $v : (int) $v;
            }
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * A ref's credit changes, newest first: each row's kind, delta, balance after it and its cause.
     * @return list<array{id: int, ts: int, kind: string, delta: int, balance_after: int, ref: string}>
     */
    public function history(string $ref, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100_000) {
            throw new HttpError(400, 'limit must be from 1 to 100000');
        }
        $st = $this->db->prepare('SELECT id, ts, kind, delta, balance_after, ref FROM credit_log WHERE key_id = ? ORDER BY id DESC LIMIT ?');
        $st->bindValue(1, $this->keyIdByRef($ref), \PDO::PARAM_INT);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->execute();
        $rows = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $rows[] = ['id' => (int) $r['id'], 'ts' => (int) $r['ts'], 'kind' => (string) $r['kind'], 'delta' => (int) $r['delta'],
                'balance_after' => (int) $r['balance_after'], 'ref' => (string) $r['ref']];
        }
        return $rows;
    }

    /**
     * Checks credit_log against the balances: for every key the deltas must sum to its balance, and
     * every row's balance_after must equal the running sum of the key's deltas up to that row.
     * Returns the number of keys and rows checked and one line per problem found (none = consistent).
     * @return array{keys: int, rows: int, problems: list<string>}
     */
    public function verify(): array
    {
        $problems = [];
        $sums = $this->db->query('SELECT k.ref, k.balance, COALESCE(SUM(c.delta), 0) AS logged, COUNT(c.id) AS n
            FROM keys k LEFT JOIN credit_log c ON c.key_id = k.id GROUP BY k.id ORDER BY k.id')->fetchAll(\PDO::FETCH_ASSOC);
        $rows = 0;
        foreach ($sums as $s) {
            $rows += (int) $s['n'];
            if ((int) $s['logged'] !== (int) $s['balance']) {
                $problems[] = "key {$s['ref']}: balance {$s['balance']} but logged changes sum to {$s['logged']}";
            }
        }
        $chain = $this->db->query('SELECT c.id, k.ref, c.balance_after, c.running FROM
                (SELECT id, key_id, balance_after, SUM(delta) OVER (PARTITION BY key_id ORDER BY id) AS running FROM credit_log) c
            JOIN keys k ON k.id = c.key_id WHERE c.running != c.balance_after ORDER BY c.id LIMIT 100')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($chain as $c) {
            $problems[] = "key {$c['ref']}: credit_log row {$c['id']} says balance_after {$c['balance_after']} but the changes up to it sum to {$c['running']}";
        }
        return ['keys' => count($sums), 'rows' => $rows, 'problems' => $problems];
    }

    /** Writes the credit_log row for a balance change just made in the current transaction. */
    private function logCredit(int $keyId, string $kind, int $delta, string $ref): void
    {
        if ($delta === 0) {
            return; // nothing changed: a refund of a fully used reservation
        }
        $this->db->prepare('INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([time(), $keyId, $kind, $delta, $this->balanceOf($keyId), $ref]);
    }

    /**
     * Requests started in the last 60 seconds, the credits they cost (charged if settled, else still
     * reserved) and when the oldest of them started.
     * @return array{requests: int, credits: int, oldest: int}
     */
    private function window(int $keyId, int $now): array
    {
        $st = $this->db->prepare('SELECT COUNT(*), COALESCE(SUM(COALESCE(u.charged, l.reserved)), 0), COALESCE(MIN(l.created_at), ?)
            FROM leases l LEFT JOIN usage u ON u.lease_id = l.id WHERE l.key_id = ? AND l.created_at > ?');
        $st->execute([$now, $keyId, $now - self::WINDOW_SECONDS]);
        $r = $st->fetch(\PDO::FETCH_NUM);
        $st->closeCursor();
        return ['requests' => (int) $r[0], 'credits' => (int) $r[1], 'oldest' => (int) $r[2]];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, ?int>
     */
    private static function overrides(array $row): array
    {
        $out = [];
        foreach (self::LIMITS as $name => $column) {
            $out[$name] = isset($row[$column]) ? (int) $row[$column] : null;
        }
        return $out;
    }

    private function keyIdByRef(string $ref): int
    {
        $st = $this->db->prepare('SELECT id FROM keys WHERE ref = ?');
        $st->execute([$ref]);
        $id = $st->fetchColumn();
        $st->closeCursor();
        if ($id === false) {
            throw new HttpError(404, 'unknown ref');
        }
        return (int) $id;
    }

    private function balanceOf(int $keyId): int
    {
        $st = $this->db->prepare('SELECT balance FROM keys WHERE id = ?');
        $st->execute([$keyId]);
        $b = (int) $st->fetchColumn();
        $st->closeCursor();
        return $b;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
}
