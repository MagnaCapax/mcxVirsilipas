<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The credit ledger: keys with one never-resetting balance each, idempotent grants,
 * per-request leases (reserve -> settle), per-key limits and a metadata-only usage log.
 *
 * Every write is a short BEGIN IMMEDIATE transaction. Nothing is held open while a
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
    private const SCHEMA_VERSION = 3;
    /** The one older schema migrate() upgrades in place (it adds credit_log). */
    private const UPGRADABLE_FROM = 2;
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
     * Creates the schema, or upgrades a schema-2 ledger in place in one transaction: credit_log is
     * added and each key with a nonzero balance gets one 'opening' row carrying that balance, so the
     * sum check holds from the first moment. Any other older or newer schema is refused.
     */
    public function migrate(): void
    {
        $version = (int) $this->db->query('PRAGMA user_version')->fetchColumn();
        $tables = (int) $this->db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'keys'")->fetchColumn();
        if ($tables > 0 && $version !== self::SCHEMA_VERSION && $version !== self::UPGRADABLE_FROM) {
            throw new \RuntimeException('this ledger was created by another version of virsilipas (schema ' . $version . ', expected ' . self::SCHEMA_VERSION . '); start a new ledger');
        }
        $upgrade = $tables > 0 && $version === self::UPGRADABLE_FROM;
        $this->tx(function () use ($upgrade): void {
            $this->createSchema();
            if ($upgrade) {
                $this->db->prepare("INSERT INTO credit_log (ts, key_id, kind, delta, balance_after, ref) SELECT ?, id, 'opening', balance, balance, 'schema-" . self::SCHEMA_VERSION . "' FROM keys WHERE balance != 0 ORDER BY id")
                    ->execute([time()]);
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

    /** @return array{id: int, ref: string, balance: int, active: bool}|null */
    public function findKey(string $plainKey): ?array
    {
        $st = $this->db->prepare('SELECT id, ref, balance, active FROM keys WHERE key_hash = ?');
        $st->execute([hash('sha256', $plainKey)]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        if ($row === false) {
            return null;
        }
        return ['id' => (int) $row['id'], 'ref' => $row['ref'], 'balance' => (int) $row['balance'], 'active' => (bool) $row['active']];
    }

    /**
     * Balance, holds, limits and recent use. `reserved` = credits held by unsettled leases (already
     * taken from the balance); `held` = expired leases never settled (their worker died), whose
     * reservations stay charged so the operator can see and reconcile them. `window` = requests and
     * credits (charged, or still reserved) over the last 60 seconds.
     * @param array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int} $defaults
     * @return array{ref: string, balance: int, active: bool, in_flight: int, held: int, reserved: int,
     *     overrides: array<string, ?int>, limits: array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int},
     *     window: array{requests: int, credits: int}}
     */
    public function status(string $ref, array $defaults): array
    {
        $now = time();
        $st = $this->db->prepare('SELECT k.id, k.ref, k.balance, k.active, k.max_concurrent, k.requests_per_minute, k.credits_per_minute,
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
            'window' => ['requests' => $w['requests'], 'credits' => $w['credits']]];
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
     * order: disabled key, concurrency, requests per minute, credits per minute, balance.
     * @param array{input: int, cached_input: int, output: int} $rates
     * @param array{max_concurrent: int, requests_per_minute: ?int, credits_per_minute: ?int} $defaults
     * @return array{lease_id: int, max_tokens: int, reserved: int}
     */
    public function reserve(int $keyId, int $promptBound, ?int $requestedMax, int $cap, array $rates, array $defaults, int $leaseSeconds): array
    {
        return $this->tx(function () use ($keyId, $promptBound, $requestedMax, $cap, $rates, $defaults, $leaseSeconds): array {
            $now = time();
            $st = $this->db->prepare('SELECT balance, active, ' . implode(', ', self::LIMITS) . ' FROM keys WHERE id = ?');
            $st->execute([$keyId]);
            $k = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($k === false || !(int) $k['active']) {
                throw new HttpError(403, 'key is disabled', 'permission_error');
            }
            $limits = self::effectiveLimits(self::overrides($k), $defaults);

            $st = $this->db->prepare('SELECT COUNT(*) FROM leases WHERE key_id = ? AND settled = 0 AND expires_at > ?');
            $st->execute([$keyId, $now]);
            if ((int) $st->fetchColumn() >= $limits['max_concurrent']) {
                throw new HttpError(429, 'too many concurrent requests for this key', 'rate_limit_error', 1);
            }

            if ($limits['requests_per_minute'] !== null || $limits['credits_per_minute'] !== null) {
                $w = $this->window($keyId, $now);
                // The earliest moment the window can free: when its oldest request turns 60 seconds old.
                $retry = max(1, $w['oldest'] + self::WINDOW_SECONDS - $now);
                if ($limits['requests_per_minute'] !== null && $w['requests'] >= $limits['requests_per_minute']) {
                    throw new HttpError(429, 'requests per minute limit reached for this key', 'rate_limit_error', $retry);
                }
                if ($limits['credits_per_minute'] !== null && $w['credits'] >= $limits['credits_per_minute']) {
                    throw new HttpError(429, 'credits per minute limit reached for this key', 'rate_limit_error', $retry);
                }
            }

            $maxTokens = min($requestedMax ?? $cap, Credits::affordable((int) $k['balance'], $promptBound, $cap, $rates));
            if ($maxTokens < 1) {
                throw new HttpError(402, 'credit balance too low for this request', 'insufficient_quota');
            }
            // Priced like a charge, rounded once: any reported usage within the prompt bound and max_tokens costs no more.
            $reserved = Credits::of($promptBound, 0, $maxTokens, $rates);

            $upd = $this->db->prepare('UPDATE keys SET balance = balance - ? WHERE id = ? AND balance >= ?');
            $upd->execute([$reserved, $keyId, $reserved]);
            if ($upd->rowCount() !== 1) {
                throw new HttpError(402, 'credit balance too low for this request', 'insufficient_quota');
            }
            $this->db->prepare('INSERT INTO leases (key_id, reserved, created_at, expires_at) VALUES (?, ?, ?, ?)')
                ->execute([$keyId, $reserved, $now, $now + $leaseSeconds]);
            $leaseId = (int) $this->db->lastInsertId();
            $this->logCredit($keyId, 'reserve', -$reserved, 'lease:' . $leaseId);

            return ['lease_id' => $leaseId, 'max_tokens' => $maxTokens, 'reserved' => $reserved];
        });
    }

    /**
     * Settles a request exactly once: charges the credits the request used, never more than the
     * reservation, returns the rest, and writes the usage row in the same transaction.
     * Settling an already settled lease does nothing and returns false.
     * @param array{model: string, prompt: int, completion: int, cached: int, status: int, aborted: bool, estimated: bool} $meta
     */
    public function settle(int $leaseId, int $keyId, int $used, array $meta): bool
    {
        return $this->tx(function () use ($leaseId, $keyId, $used, $meta): bool {
            $st = $this->db->prepare('SELECT reserved FROM leases WHERE id = ? AND key_id = ? AND settled = 0');
            $st->execute([$leaseId, $keyId]);
            $reserved = $st->fetchColumn();
            $st->closeCursor();
            if ($reserved === false) {
                return false;
            }
            $reserved = (int) $reserved;
            $used = max(0, $used);
            $charged = min($used, $reserved);
            $this->db->prepare('UPDATE leases SET settled = 1 WHERE id = ?')->execute([$leaseId]);
            $this->db->prepare('UPDATE keys SET balance = balance + ? WHERE id = ?')->execute([$reserved - $charged, $keyId]);
            $this->logCredit($keyId, 'refund', $reserved - $charged, 'lease:' . $leaseId);
            $this->db->prepare('INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, cached_tokens, reported, charged, http_status, aborted, estimated, over_reservation) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([time(), $keyId, $leaseId, $meta['model'], $meta['prompt'], $meta['completion'], $meta['cached'], $used, $charged, $meta['status'], (int) $meta['aborted'], (int) $meta['estimated'], (int) ($used > $reserved)]);
            return true;
        });
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
