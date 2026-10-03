<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The token ledger: keys with one never-resetting balance each, idempotent grants,
 * per-request leases (reserve -> settle) and a metadata-only usage log.
 *
 * Every write is a short BEGIN IMMEDIATE transaction. Nothing is held open while a
 * request streams, so a slow client never blocks other keys.
 */
final class Ledger
{
    private \PDO $db;

    public function __construct(string $path)
    {
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
                -- AUTOINCREMENT: a lease id is never reused, so a late settle can never close another request's lease
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_id INTEGER NOT NULL REFERENCES keys(id),
                reserved INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS leases_key ON leases(key_id);
            CREATE TABLE IF NOT EXISTS usage (
                id INTEGER PRIMARY KEY,
                ts INTEGER NOT NULL,
                key_id INTEGER NOT NULL REFERENCES keys(id),
                model TEXT NOT NULL,
                prompt_tokens INTEGER NOT NULL,
                completion_tokens INTEGER NOT NULL,
                cached_tokens INTEGER NOT NULL,
                charged INTEGER NOT NULL,
                http_status INTEGER NOT NULL,
                aborted INTEGER NOT NULL,
                estimated INTEGER NOT NULL
            );
            SQL);
    }

    /** Creates a key for an external reference (e.g. a billing service id). Returns the plaintext key, shown once. */
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
     * @return array{applied: bool, balance: int}
     */
    public function grant(string $ref, string $grantId, int $tokens): array
    {
        if ($tokens === 0 || $grantId === '') {
            throw new HttpError(400, 'grant needs a grant_id and nonzero tokens');
        }
        return $this->tx(function () use ($ref, $grantId, $tokens): array {
            $keyId = $this->keyIdByRef($ref);
            $ins = $this->db->prepare('INSERT OR IGNORE INTO grants (grant_id, key_id, tokens, created_at) VALUES (?, ?, ?, ?)');
            $ins->execute([$grantId, $keyId, $tokens, time()]);
            $applied = $ins->rowCount() === 1;
            if ($applied) {
                $this->db->prepare('UPDATE keys SET balance = balance + ? WHERE id = ?')->execute([$tokens, $keyId]);
            }
            return ['applied' => $applied, 'balance' => $this->balanceOf($keyId)];
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

    /** @return array{ref: string, balance: int, active: bool, in_flight: int} */
    public function status(string $ref): array
    {
        $st = $this->db->prepare('SELECT k.ref, k.balance, k.active, (SELECT COUNT(*) FROM leases l WHERE l.key_id = k.id) AS in_flight FROM keys k WHERE k.ref = ?');
        $st->execute([$ref]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        $st->closeCursor();
        if ($row === false) {
            throw new HttpError(404, 'unknown ref');
        }
        return ['ref' => $row['ref'], 'balance' => (int) $row['balance'], 'active' => (bool) $row['active'], 'in_flight' => (int) $row['in_flight']];
    }

    /**
     * Reserves tokens for one request. max_tokens is clamped so the reservation never exceeds the
     * balance; the lease row also counts against the key's concurrency limit.
     * @return array{lease_id: int, max_tokens: int, reserved: int}
     */
    public function reserve(int $keyId, int $promptEstimate, ?int $requestedMax, int $cap, int $maxConcurrent, int $leaseSeconds): array
    {
        // Reap in its own transaction: a refusal below rolls back, and must not roll back the refund.
        $this->tx(fn () => $this->reapExpired($keyId, time()));

        return $this->tx(function () use ($keyId, $promptEstimate, $requestedMax, $cap, $maxConcurrent, $leaseSeconds): array {
            $now = time();
            $st = $this->db->prepare('SELECT balance, active FROM keys WHERE id = ?');
            $st->execute([$keyId]);
            $k = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($k === false || !(int) $k['active']) {
                throw new HttpError(403, 'key is disabled', 'permission_error');
            }

            $st = $this->db->prepare('SELECT COUNT(*) FROM leases WHERE key_id = ?');
            $st->execute([$keyId]);
            if ((int) $st->fetchColumn() >= $maxConcurrent) {
                throw new HttpError(429, 'too many concurrent requests for this key', 'rate_limit_error');
            }

            $available = (int) $k['balance'] - $promptEstimate;
            if ($available < 1) {
                throw new HttpError(402, 'token balance exhausted', 'insufficient_quota');
            }
            $maxTokens = min($requestedMax ?? $cap, $cap, $available);
            $reserved = $promptEstimate + $maxTokens;

            $upd = $this->db->prepare('UPDATE keys SET balance = balance - ? WHERE id = ? AND balance >= ?');
            $upd->execute([$reserved, $keyId, $reserved]);
            if ($upd->rowCount() !== 1) {
                throw new HttpError(402, 'token balance exhausted', 'insufficient_quota');
            }
            $this->db->prepare('INSERT INTO leases (key_id, reserved, expires_at) VALUES (?, ?, ?)')
                ->execute([$keyId, $reserved, $now + $leaseSeconds]);

            return ['lease_id' => (int) $this->db->lastInsertId(), 'max_tokens' => $maxTokens, 'reserved' => $reserved];
        });
    }

    /**
     * Settles a request: returns the reservation and debits what was actually used, and writes the
     * usage row in the same transaction. If the lease was already reaped (its reservation refunded),
     * only the usage is debited.
     * @param array{model: string, prompt: int, completion: int, cached: int, status: int, aborted: bool, estimated: bool} $meta
     */
    public function settle(int $leaseId, int $keyId, int $used, array $meta): void
    {
        $this->tx(function () use ($leaseId, $keyId, $used, $meta): void {
            $st = $this->db->prepare('SELECT reserved FROM leases WHERE id = ? AND key_id = ?');
            $st->execute([$leaseId, $keyId]);
            $reserved = $st->fetchColumn();
            $st->closeCursor();
            if ($reserved !== false) {
                $this->db->prepare('DELETE FROM leases WHERE id = ?')->execute([$leaseId]);
            }
            $delta = (int) ($reserved === false ? 0 : $reserved) - $used;
            $this->db->prepare('UPDATE keys SET balance = balance + ? WHERE id = ?')->execute([$delta, $keyId]);
            $this->db->prepare('INSERT INTO usage (ts, key_id, model, prompt_tokens, completion_tokens, cached_tokens, charged, http_status, aborted, estimated) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([time(), $keyId, $meta['model'], $meta['prompt'], $meta['completion'], $meta['cached'], $used, $meta['status'], (int) $meta['aborted'], (int) $meta['estimated']]);
        });
    }

    /** Leases outlive their request only if the worker died; refund their reservation. */
    private function reapExpired(int $keyId, int $now): void
    {
        $st = $this->db->prepare('SELECT COALESCE(SUM(reserved), 0) FROM leases WHERE key_id = ? AND expires_at <= ?');
        $st->execute([$keyId, $now]);
        $refund = (int) $st->fetchColumn();
        if ($refund > 0) {
            $this->db->prepare('DELETE FROM leases WHERE key_id = ? AND expires_at <= ?')->execute([$keyId, $now]);
            $this->db->prepare('UPDATE keys SET balance = balance + ? WHERE id = ?')->execute([$refund, $keyId]);
        }
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
