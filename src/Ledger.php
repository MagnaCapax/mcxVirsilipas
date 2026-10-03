<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The token ledger: keys with one never-resetting balance each, idempotent grants,
 * per-request leases (reserve -> settle) and a metadata-only usage log.
 *
 * Every write is a short BEGIN IMMEDIATE transaction. Nothing is held open while a
 * request streams, so a slow client never blocks other keys.
 *
 * A lease's reservation is taken from the balance when the request starts and stays
 * taken until the request is settled. A lease that outlives its request (its worker
 * died) stops counting against concurrency when it expires, but its reservation stays
 * charged: the request may already have been answered. A late settle still corrects it.
 */
final class Ledger
{
    /** Largest grant accepted in one call, and the largest balance magnitude kept. Far inside int64. */
    public const MAX_GRANT = 1_000_000_000_000_000;
    private const MAX_BALANCE = 1_000_000_000_000_000_000;

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
        $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout = 10000');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
    }

    public function migrate(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS keys (
                id INTEGER PRIMARY KEY,
                ref TEXT NOT NULL UNIQUE,
                key_hash TEXT NOT NULL UNIQUE,
                balance INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS grants (
                grant_id TEXT PRIMARY KEY,
                key_id INTEGER NOT NULL REFERENCES keys(id),
                tokens INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS leases (
                -- AUTOINCREMENT: a lease id is never reused, so a settle can only ever close its own lease
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_id INTEGER NOT NULL REFERENCES keys(id),
                reserved INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                settled INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS leases_open ON leases(key_id, settled, expires_at);
            CREATE TABLE IF NOT EXISTS usage (
                id INTEGER PRIMARY KEY,
                ts INTEGER NOT NULL,
                key_id INTEGER NOT NULL REFERENCES keys(id),
                lease_id INTEGER NOT NULL UNIQUE REFERENCES leases(id),
                model TEXT NOT NULL,
                prompt_tokens INTEGER NOT NULL,
                completion_tokens INTEGER NOT NULL,
                reported INTEGER NOT NULL,
                charged INTEGER NOT NULL,
                http_status INTEGER NOT NULL,
                aborted INTEGER NOT NULL,
                estimated INTEGER NOT NULL,
                over_reservation INTEGER NOT NULL
            );
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
     * Applies a billing grant once per grant id. Tokens may be negative (refund or chargeback).
     * Retrying with the same grant id and the same ref and amount is a no-op; reusing a grant id
     * for anything else is a conflict.
     * @return array{applied: bool, balance: int}
     */
    public function grant(string $ref, string $grantId, int $tokens): array
    {
        if ($tokens === 0 || abs($tokens) > self::MAX_GRANT || $grantId === '') {
            throw new HttpError(400, 'grant needs a grant_id and nonzero tokens of at most ' . self::MAX_GRANT);
        }
        return $this->tx(function () use ($ref, $grantId, $tokens): array {
            $keyId = $this->keyIdByRef($ref);
            $st = $this->db->prepare('SELECT key_id, tokens FROM grants WHERE grant_id = ?');
            $st->execute([$grantId]);
            $prior = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($prior !== false) {
                if ((int) $prior['key_id'] !== $keyId || (int) $prior['tokens'] !== $tokens) {
                    throw new HttpError(409, 'grant_id was already used for a different ref or amount');
                }
                return ['applied' => false, 'balance' => $this->balanceOf($keyId)];
            }
            $new = $this->balanceOf($keyId) + $tokens;
            if (abs($new) > self::MAX_BALANCE) {
                throw new HttpError(400, 'grant would take the balance out of range');
            }
            $this->db->prepare('INSERT INTO grants (grant_id, key_id, tokens, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$grantId, $keyId, $tokens, time()]);
            $this->db->prepare('UPDATE keys SET balance = balance + ? WHERE id = ?')->execute([$tokens, $keyId]);
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
     * Balance and lease counts. `held` = expired leases never settled (their worker died); their
     * reservations stay charged, so the operator can see and reconcile them.
     * @return array{ref: string, balance: int, active: bool, in_flight: int, held: int}
     */
    public function status(string $ref): array
    {
        $now = time();
        $st = $this->db->prepare('SELECT k.ref, k.balance, k.active,
                (SELECT COUNT(*) FROM leases l WHERE l.key_id = k.id AND l.settled = 0 AND l.expires_at > ?) AS in_flight,
                (SELECT COUNT(*) FROM leases l WHERE l.key_id = k.id AND l.settled = 0 AND l.expires_at <= ?) AS held
            FROM keys k WHERE k.ref = ?');
        $st->execute([$now, $now, $ref]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        if ($row === false) {
            throw new HttpError(404, 'unknown ref');
        }
        return ['ref' => $row['ref'], 'balance' => (int) $row['balance'], 'active' => (bool) $row['active'], 'in_flight' => (int) $row['in_flight'], 'held' => (int) $row['held']];
    }

    /**
     * Reserves tokens for one request. max_tokens is clamped so the reservation never exceeds the
     * balance; the lease also counts against the key's concurrency limit until settled or expired.
     * @return array{lease_id: int, max_tokens: int, reserved: int}
     */
    public function reserve(int $keyId, int $promptBound, ?int $requestedMax, int $cap, int $maxConcurrent, int $leaseSeconds): array
    {
        return $this->tx(function () use ($keyId, $promptBound, $requestedMax, $cap, $maxConcurrent, $leaseSeconds): array {
            $now = time();
            $st = $this->db->prepare('SELECT balance, active FROM keys WHERE id = ?');
            $st->execute([$keyId]);
            $k = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($k === false || !(int) $k['active']) {
                throw new HttpError(403, 'key is disabled', 'permission_error');
            }

            $st = $this->db->prepare('SELECT COUNT(*) FROM leases WHERE key_id = ? AND settled = 0 AND expires_at > ?');
            $st->execute([$keyId, $now]);
            if ((int) $st->fetchColumn() >= $maxConcurrent) {
                throw new HttpError(429, 'too many concurrent requests for this key', 'rate_limit_error');
            }

            $available = (int) $k['balance'] - $promptBound;
            if ($available < 1) {
                throw new HttpError(402, 'token balance too low for this request', 'insufficient_quota');
            }
            $maxTokens = min($requestedMax ?? $cap, $cap, $available);
            $reserved = $promptBound + $maxTokens;

            $upd = $this->db->prepare('UPDATE keys SET balance = balance - ? WHERE id = ? AND balance >= ?');
            $upd->execute([$reserved, $keyId, $reserved]);
            if ($upd->rowCount() !== 1) {
                throw new HttpError(402, 'token balance too low for this request', 'insufficient_quota');
            }
            $this->db->prepare('INSERT INTO leases (key_id, reserved, expires_at) VALUES (?, ?, ?)')
                ->execute([$keyId, $reserved, $now + $leaseSeconds]);

            return ['lease_id' => (int) $this->db->lastInsertId(), 'max_tokens' => $maxTokens, 'reserved' => $reserved];
        });
    }

    /**
     * Settles a request exactly once: charges what the upstream reported, never more than the
     * reservation, returns the rest, and writes the usage row in the same transaction.
     * Settling an already settled lease does nothing and returns false.
     * @param array{model: string, prompt: int, completion: int, status: int, aborted: bool, estimated: bool} $meta
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
            $this->db->prepare('INSERT INTO usage (ts, key_id, lease_id, model, prompt_tokens, completion_tokens, reported, charged, http_status, aborted, estimated, over_reservation) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([time(), $keyId, $leaseId, $meta['model'], $meta['prompt'], $meta['completion'], $used, $charged, $meta['status'], (int) $meta['aborted'], (int) $meta['estimated'], (int) ($used > $reserved)]);
            return true;
        });
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
